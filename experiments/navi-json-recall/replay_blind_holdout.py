#!/usr/bin/env python3
"""Replay a checked-in, unverified local LLM transcript against CURRENT owner JSON.

This verifies record consistency and candidate availability, NOT provenance
of live model invocation. The model is never called from this CI action.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from collections import Counter
from pathlib import Path
from typing import Any

from run_blind_holdout_llm_benchmark import (
    evaluate_blind_holdout,
    validate_bounded_choice_response,
)


def replay(
    source: Path,
    metadata: Path,
    queries: Path,
    recorded_path: Path,
) -> dict[str, Any]:
    recorded_bytes = recorded_path.read_bytes()
    recorded: Any = json.loads(recorded_bytes)
    if not isinstance(recorded, dict) or recorded.get("case_count") != 26:
        raise ValueError("Expected exactly 26 recorded local-model cases")
    results = recorded.get("cases")
    if not isinstance(results, list) or len(results) != 26:
        raise ValueError("Missing recorded case-level evidence")

    # Compute non-LLM baselines independently, with the current owner JSON.
    baseline = evaluate_blind_holdout(
        source_path=source,
        metadata_path=metadata,
        queries_path=queries,
        run_llm=False,
    )
    evaluated = baseline["cases"]
    if len(evaluated) != len(results):
        raise ValueError("Recorded and current case counts differ")

    counters: dict[str, Counter[str]] = {
        cohort: Counter()
        for cohort in ("blind_positive", "blind_hard_negative", "blind_out_of_catalog")
    }
    mismatches: list[dict[str, str]] = []
    recaps: list[dict[str, Any]] = []
    for n, (old, fresh) in enumerate(zip(results, evaluated), start=1):
        if (
            old.get("query") != fresh["query"]
            or old.get("cohort") != fresh["cohort"]
            or old.get("expected_id") != fresh["expected_id"]
        ):
            raise ValueError(f"Recorded holdout query drift at case {n}")

        for method in ("sqlite_fts5", "metadata_lexical", "metadata_60pct_gate"):
            if old["predictions"].get(method) != fresh["predictions"][method]:
                raise ValueError(f"Candidate ranking drift at case {n}: {method}")

        offered = (
            fresh["predictions"]["metadata_lexical"]
            or fresh["predictions"]["sqlite_fts5"]
        )
        if old.get("llm_candidates_offered") != offered:
            raise ValueError(f"Recorded candidate set drift at case {n}")
        raw = old.get("llm_raw_response")
        if not isinstance(raw, str):
            raise ValueError(f"Missing raw model response at case {n}")

        decision = validate_bounded_choice_response(raw, offered)
        logged = old["predictions"].get("live_llm_bounded_choice")
        if logged != ([decision] if decision is not None else []):
            raise ValueError(f"Recorded model choice mismatch at case {n}")
        expected = fresh["expected_id"]
        cohort = fresh["cohort"]
        stats = counters[cohort]
        stats["total"] += 1
        if expected is not None and expected in offered:
            stats["gold_in_candidates"] += 1
        if expected is not None and decision == expected:
            stats["correct_positive"] += 1
        if expected is None and decision is None:
            stats["recorded_abstention"] += 1
        if expected is None and decision is not None:
            stats["false_positive"] += 1
        if not raw:
            stats["empty_response"] += 1
        elif raw.startswith("ERROR:"):
            stats["execution_error"] += 1
        elif decision is None and raw.strip() != '{"selected": "none"}' and raw.strip() != '{"selected":"none"}':
            stats["unselected_or_invalid_format"] += 1
        if expected is not None and expected not in offered:
            mismatches.append({
                "cohort": cohort,
                "expected_id": expected,
                "reason": "gold_not_offered_to_model",
            })
        elif expected is not None and decision != expected:
            mismatches.append({
                "cohort": cohort,
                "expected_id": expected,
                "reason": "offered_but_model_did_not_select",
            })
        elif expected is None and decision is not None:
            mismatches.append({
                "cohort": cohort,
                "selected_id": decision,
                "reason": "incorrect_out_of_catalog_selection",
            })
        recaps.append({
            "case": n,
            "cohort": cohort,
            "expected_id": expected,
            "offered_count": len(offered),
            "gold_in_candidates": expected in offered if expected is not None else None,
            "raw_response_empty": not raw,
            "selected": decision,
        })
    return {
        "status": "pass",
        "evidence_class": "local_recorded_model_output_replayed_not_live_ci_inference",
        "live_model_invocations_in_ci": 0,
        "provider_model_identity_verified": False,
        "dataset_independence_verified": False,
        "method": "verify recorded model JSON against live current candidate rankings, never call model",
        "source_sha256": hashlib.sha256(source.read_bytes()).hexdigest(),
        "metadata_sha256": hashlib.sha256(metadata.read_bytes()).hexdigest(),
        "queries_sha256": hashlib.sha256(queries.read_bytes()).hexdigest(),
        "recorded_evidence_sha256": hashlib.sha256(recorded_bytes).hexdigest(),
        "cases": len(recaps),
        "cohorts": {key: dict(value) for key, value in counters.items()},
        "failure_attribution": mismatches,
        "case_audit": recaps,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True, type=Path)
    parser.add_argument("--metadata", required=True, type=Path)
    parser.add_argument("--queries", required=True, type=Path)
    parser.add_argument("--recorded", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    result = replay(args.source, args.metadata, args.queries, args.recorded)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({
        "status": result["status"],
        "evidence_class": result["evidence_class"],
        "cohorts": result["cohorts"],
        "failure_attribution": result["failure_attribution"],
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
