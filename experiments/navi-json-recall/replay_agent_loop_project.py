#!/usr/bin/env python3
"""Verify the committed agent-loop project-model transcript against owner JSON.

This deterministic CI replay NEVER calls a model. A pass proves that stored
answers can be decoded with current candidates and that cohort metrics were
recomputed independently of PR prose; it does NOT establish model provenance.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
from typing import Any

from benchmark import build_fts, build_metadata_fts, fts_rank, metadata_bounded_rank
from run_applicability_ab_test import validate_choice

COHORTS = ("project_positive", "project_out_of_catalog", "hard_domain_negative")


def read_json(path: Path) -> Any:
    if not path.is_file() or path.is_symlink():
        raise ValueError(f"Missing or symlinked evidence input: {path}")
    return json.loads(path.read_text(encoding="utf-8"))


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def replay(
    source: Path,
    metadata: Path,
    queries: Path,
    recorded: Path,
) -> dict[str, Any]:
    source_data = read_json(source)
    if source_data.get("schema_version") != "1.0" or not isinstance(source_data.get("prompts"), list):
        raise ValueError("Invalid canonical prompt catalog")
    indexed = {item["id"]: item["template"] for item in source_data["prompts"]}
    if len(indexed) != len(source_data["prompts"]):
        raise ValueError("Duplicate canonical prompt IDs")

    fixture = read_json(queries)
    if fixture.get("schema_version") != "1.0" or not isinstance(fixture.get("queries"), list):
        raise ValueError("Invalid agent-loop fixture")
    cases = fixture["queries"]
    if len(cases) != 18:
        raise ValueError("Expected exactly 18 registered agent-loop cases")

    transcript = read_json(recorded)
    entries = transcript.get("cases")
    if transcript.get("source") != "voku/agent-loop" or transcript.get("case_count") != 18:
        raise ValueError("Unexpected agent-loop transcript identity or count")
    if not isinstance(entries, list) or len(entries) != 18:
        raise ValueError("Missing transcript entries")

    conn, meta = build_metadata_fts(metadata, indexed)
    plain = build_fts(indexed)
    summaries = {
        name: {
            "total": 0,
            "correct_with_fail_closed": 0,
            "correct_explicit": 0,
            "explicit_none": 0,
            "empty": 0,
            "wrong_recipe": 0,
            "missing_expected_candidate": 0,
            "expected_offered_but_not_selected": 0,
        }
        for name in COHORTS
    }
    failures: list[dict[str, Any]] = []
    try:
        for n, (expected, actual) in enumerate(zip(cases, entries), start=1):
            for key in ("reference", "cohort", "query", "expected_id", "rationale"):
                if expected.get(key) != actual.get(key):
                    raise ValueError(f"Recorded {key} differs from owner fixture at case {n}")
            cohort = actual["cohort"]
            if cohort not in summaries:
                raise ValueError(f"Unexpected cohort at case {n}: {cohort}")
            gold = expected["expected_id"]
            if cohort == "project_positive":
                if not isinstance(gold, str) or gold not in indexed:
                    raise ValueError(f"Invalid positive gold label at case {n}")
            elif gold is not None:
                raise ValueError(f"Non-positive case {n} has a positive gold label")

            offered = actual.get("llm_candidates_offered")
            if not isinstance(offered, list) or not all(isinstance(cid, str) for cid in offered):
                raise ValueError(f"Malformed candidate list at case {n}")
            if len(offered) > 3 or len(offered) != len(set(offered)) or not set(offered) <= set(indexed):
                raise ValueError(f"Unverified or unbounded candidates at case {n}")
            ranked = metadata_bounded_rank(conn, meta, indexed, expected["query"])[:3]
            if not ranked:
                # The original benchmark explicitly used plain FTS5
                # distractors whenever metadata yielded no candidates.
                ranked = fts_rank(plain, expected["query"])[:3]
            if offered != ranked:
                raise ValueError(
                    f"Candidate ranking differs from metadata→plain-FTS fallback at case {n}: "
                    f"expected={ranked!r}, recorded={offered!r}"
                )

            raw = actual.get("llm_raw_response")
            if not isinstance(raw, str):
                raise ValueError(f"Invalid raw model response at case {n}")
            decision = validate_choice(raw, offered)
            predicted = actual.get("predictions", {}).get("live_llm_bounded_choice")
            if predicted != ([decision] if decision is not None else []):
                raise ValueError(f"Model selection drift at case {n}")

            totals = summaries[cohort]
            totals["total"] += 1
            correct = decision == gold
            if correct:
                totals["correct_with_fail_closed"] += 1
            if raw == "":
                totals["empty"] += 1
            if raw in ('{"selected": "none"}', '{"selected":"none"}'):
                totals["explicit_none"] += 1
                if correct:
                    totals["correct_explicit"] += 1
            elif decision is not None:
                if correct:
                    totals["correct_explicit"] += 1
                else:
                    totals["wrong_recipe"] += 1
            if gold is not None and gold not in offered:
                totals["missing_expected_candidate"] += 1
            elif gold is not None and not correct:
                totals["expected_offered_but_not_selected"] += 1
            if not correct:
                failures.append({
                    "reference": expected["reference"],
                    "cohort": cohort,
                    "expected_id": gold,
                    "candidate_available": gold in offered if gold is not None else None,
                    "selected": decision,
                    "empty_raw_response": raw == "",
                })
    finally:
        conn.close()
        plain.close()

    overall = {
        "total": sum(x["total"] for x in summaries.values()),
        "correct_with_fail_closed": sum(x["correct_with_fail_closed"] for x in summaries.values()),
        "correct_explicit": sum(x["correct_explicit"] for x in summaries.values()),
        "empty": sum(x["empty"] for x in summaries.values()),
    }
    if overall["total"] != 18:
        raise AssertionError("Cohort coverage changed")
    return {
        "status": "pass",
        "evidence_class": "stored_local_model_outputs_replayed_not_live_inference",
        "model_invocations_in_ci": 0,
        "model_identity_verified": False,
        "replay_cannot_prove_independent_gold_labels": True,
        "source_sha256": digest(source),
        "metadata_sha256": digest(metadata),
        "queries_sha256": digest(queries),
        "recorded_sha256": digest(recorded),
        "overall": overall,
        "cohorts": summaries,
        "failures": failures,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    for name in ("source", "metadata", "queries", "recorded", "output"):
        parser.add_argument("--" + name, type=Path, required=True)
    args = parser.parse_args()
    result = replay(args.source, args.metadata, args.queries, args.recorded)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({
        "evidence_class": result["evidence_class"],
        "overall": result["overall"],
        "cohorts": result["cohorts"],
        "failures": result["failures"],
    }, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
