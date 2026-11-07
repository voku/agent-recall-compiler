#!/usr/bin/env python3
"""Exact-source replay of a recorded, local applicability A/B experiment.

This does not call an LLM and does not establish how/when the original local
model invocations ran. It validates labels, candidate construction and every
recorded response, including explicit none vs fail-closed empty responses.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
from typing import Any

from benchmark import build_metadata_fts, metadata_bounded_rank
from run_applicability_ab_test import validate_choice


def load_json(path: Path) -> Any:
    if not path.is_file() or path.is_symlink():
        raise ValueError(f"Missing or symlinked input: {path}")
    return json.loads(path.read_text(encoding="utf-8"))


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def replay(source: Path, metadata: Path, queries: Path, recorded: Path) -> dict[str, Any]:
    catalog = load_json(source)
    if catalog.get("schema_version") != "1.0":
        raise ValueError("Unsupported source schema")
    indexed = {entry["id"]: entry["template"] for entry in catalog["prompts"]}
    if len(indexed) != len(catalog["prompts"]):
        raise ValueError("Duplicate source recipe IDs")

    fixture = load_json(queries)
    if fixture.get("schema_version") != "1.0":
        raise ValueError("Unsupported A/B fixture schema")
    pairs = fixture.get("pairs")
    if not isinstance(pairs, list) or len(pairs) != 5:
        raise ValueError("Expected exactly five pre-recorded contrast pairs")

    evidence = load_json(recorded)
    rows = evidence.get("cases")
    if evidence.get("case_count") != 10 or not isinstance(rows, list) or len(rows) != 10:
        raise ValueError("Expected exactly ten recorded decisions")

    db, meta_by_id = build_metadata_fts(metadata, indexed)
    stats: dict[str, dict[str, int]] = {
        name: {
            "total": 0,
            "correct_with_fail_closed": 0,
            "correct_explicit": 0,
            "wrong_candidate": 0,
            "explicit_none": 0,
            "empty_response": 0,
            "invalid_response": 0,
            "model_error": 0,
        }
        for name in ("legacy", "contract")
    }
    pairs_checked = 0
    target_in_top3 = 0
    targets_injected = 0
    try:
        pos = 0
        for pair in pairs:
            ident = pair["pair_id"]
            target = pair["target_recipe"]
            if target not in indexed:
                raise ValueError(f"Unknown recipe target: {target}")
            contrast = pair.get("cases")
            if not isinstance(contrast, list) or len(contrast) != 2:
                raise ValueError(f"Invalid contrast size: {ident}")
            roles = {entry["variant_role"] for entry in contrast}
            if roles != {"topic_only", "workflow_demanded"}:
                raise ValueError(f"Invalid contrast roles: {ident}")
            pairs_checked += 1

            for case in contrast:
                actual = rows[pos]
                pos += 1
                for key in ("query", "variant_role"):
                    if actual.get(key) != case[key]:
                        raise ValueError(f"Recorded case {pos} diverged at {key}")
                if actual.get("pair_id") != ident:
                    raise ValueError(f"Recorded pair ID mismatch at case {pos}")
                for key in ("expected_legacy", "expected_contract"):
                    if actual.get(key) != case.get(key):
                        raise ValueError(f"Recorded label mismatch at case {pos}: {key}")
                expected_contract = case["expected_contract"]
                if expected_contract not in (None, target):
                    raise ValueError(f"Unexpected contract label at case {pos}")
                if case["variant_role"] == "topic_only" and expected_contract is not None:
                    raise ValueError(f"Topic-only case {pos} incorrectly gold-labelled")

                fts = metadata_bounded_rank(db, meta_by_id, indexed, case["query"])[:3]
                if target in fts:
                    candidates = fts
                    target_in_top3 += 1
                else:
                    candidates = [target, *[c for c in fts if c != target][:2]]
                    targets_injected += 1
                if actual.get("candidates_offered") != candidates:
                    raise ValueError(f"Recorded candidates drifted at case {pos}")
                if len(candidates) != len(set(candidates)) or not set(candidates) <= set(indexed):
                    raise ValueError(f"Untrusted candidates at case {pos}")

                for name, key in (("legacy", "variant_a_legacy"), ("contract", "variant_b_contract")):
                    record = actual.get(key)
                    if not isinstance(record, dict):
                        raise ValueError(f"Missing {name} decision for case {pos}")
                    raw = record.get("raw")
                    if not isinstance(raw, str):
                        raise ValueError(f"Non-string model output for case {pos}")
                    selected = validate_choice(raw, candidates)
                    if record.get("selected") != selected:
                        raise ValueError(f"Selection drift at case {pos} ({name})")
                    model_error = record.get("model_error")
                    if model_error is not None and (
                        not isinstance(model_error, str) or not model_error
                        or raw != "" or selected is not None
                    ):
                        raise ValueError(f"Invalid model transport error at case {pos} ({name})")
                    correct = model_error is None and selected == expected_contract
                    if record.get("correct_vs_contract") is not correct:
                        raise ValueError(f"Recorded correctness drift at case {pos} ({name})")
                    metric = stats[name]
                    metric["total"] += 1
                    if correct:
                        metric["correct_with_fail_closed"] += 1
                    if model_error is not None:
                        metric["model_error"] += 1
                    elif not raw.strip():
                        metric["empty_response"] += 1
                    elif raw.strip() == '{"selected": "none"}' or raw.strip() == '{"selected":"none"}':
                        metric["explicit_none"] += 1
                        if correct:
                            metric["correct_explicit"] += 1
                    elif selected is not None:
                        if correct:
                            metric["correct_explicit"] += 1
                        else:
                            metric["wrong_candidate"] += 1
                    else:
                        metric["invalid_response"] += 1
    finally:
        db.close()

    return {
        "status": "pass",
        "evidence_class": "recorded_local_model_decisions_replayed_no_ci_live_inference",
        "live_model_calls_in_ci": 0,
        "model_identity_verified": False,
        "dataset_temporal_independence_verified": False,
        "source_sha256": digest(source),
        "metadata_sha256": digest(metadata),
        "queries_sha256": digest(queries),
        "recorded_sha256": digest(recorded),
        "pairs_checked": pairs_checked,
        "cases_checked": len(rows),
        "target_present_in_fts_top3_cases": target_in_top3,
        "target_forced_into_candidates_cases": targets_injected,
        "metrics": stats,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    for key in ("source", "metadata", "queries", "recorded", "output"):
        parser.add_argument("--" + key, type=Path, required=True)
    args = parser.parse_args()
    result = replay(args.source, args.metadata, args.queries, args.recorded)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(
        json.dumps(result, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    print(json.dumps({
        "evidence_class": result["evidence_class"],
        "target_forced_into_candidates_cases": result["target_forced_into_candidates_cases"],
        "metrics": result["metrics"],
    }, sort_keys=True, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
