#!/usr/bin/env python3
"""Replay a source-consensus veto against frozen recorded Recall research outputs.

This is a retrospective diagnostic on already-inspected development cases.
It does not invoke a model, validate original inference provenance, or
establish a prospective accuracy improvement. No product behavior is changed.
"""

from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path
import re
import subprocess
from typing import Any

SOURCE_COMMIT = "b560fb6d09bc49d19cf8ff5154f44f141cbb0953"
SOURCE_BLOB = "551a21eb67389a7cf8ef46945e80dde0ed7769c5"
SOURCE_PATH = "experiments/navi-json-recall/evidence/blind-holdout-benchmark.json"
COHORTS = {"blind_positive": 10, "blind_hard_negative": 8, "blind_out_of_catalog": 8}
EXPLICIT_NONE = re.compile(r'[ \t\r\n]*\{[ \t\r\n]*"selected"[ \t\r\n]*:[ \t\r\n]*"none"[ \t\r\n]*\}[ \t\r\n]*')


def read_pinned_evidence() -> tuple[dict[str, Any], str]:
    result = subprocess.run(
        ["git", "show", f"{SOURCE_COMMIT}:{SOURCE_PATH}"],
        check=True, capture_output=True,
    )
    raw = result.stdout
    blob = hashlib.sha1(f"blob {len(raw)}\\0".encode("ascii") + raw).hexdigest()
    if blob != SOURCE_BLOB:
        raise ValueError(f"Frozen evidence blob changed: {blob}")
    evidence = json.loads(raw)
    if not isinstance(evidence, dict) or evidence.get("case_count") != 26:
        raise ValueError("Frozen evidence must contain exactly 26 cases")
    return evidence, hashlib.sha256(raw).hexdigest()


def evaluate(evidence: dict[str, Any]) -> dict[str, Any]:
    cases = evidence.get("cases")
    if not isinstance(cases, list) or len(cases) != 26:
        raise ValueError("Frozen case list mismatch")

    counts: dict[str, Counter[str]] = {key: Counter() for key in COHORTS}
    vetoed: list[dict[str, Any]] = []
    for index, case in enumerate(cases):
        if not isinstance(case, dict):
            raise ValueError(f"Malformed case {index}")
        cohort = case.get("cohort")
        if cohort not in counts:
            raise ValueError(f"Unknown cohort at {index}")
        predictions = case.get("predictions")
        if not isinstance(predictions, dict):
            raise ValueError(f"No predictions at {index}")
        metadata = predictions.get("metadata_lexical")
        offered = case.get("llm_candidates_offered")
        selected = predictions.get("live_llm_bounded_choice")
        raw = case.get("llm_raw_response")
        expected = case.get("expected_id")
        if (
            not isinstance(metadata, list) or
            not isinstance(offered, list) or
            not isinstance(selected, list) or
            len(selected) > 1 or
            not isinstance(raw, str) or
            any(not isinstance(x, str) for x in metadata + offered + selected) or
            (expected is not None and not isinstance(expected, str))
        ):
            raise ValueError(f"Malformed source/decision fields at {index}")
        if selected and selected[0] not in offered:
            raise ValueError(f"Selected recipe was never offered at {index}")

        selection = selected[0] if selected else None
        explicit_none = not selected and EXPLICIT_NONE.fullmatch(raw) is not None
        invalid_or_empty = selection is None and not explicit_none
        source_veto = selection is not None and selection not in metadata
        accepted = None if source_veto else selection
        c = counts[cohort]
        c["total"] += 1
        c["model_correct_selection"] += int(expected is not None and selection == expected)
        c["veto_correct_selection"] += int(expected is not None and accepted == expected)
        c["model_wrong_selection"] += int(expected is None and selection is not None)
        c["veto_wrong_selection"] += int(expected is None and accepted is not None)
        c["model_explicit_none"] += int(expected is None and explicit_none)
        c["withheld_selection"] += int(source_veto)
        c["invalid_or_empty_model_response"] += int(invalid_or_empty)
        if source_veto:
            vetoed.append({
                "case_index": index,
                "cohort": cohort,
                "expected_id": expected,
                "model_selected": selection,
                "metadata_has_candidates": bool(metadata),
            })

    for cohort, expected_count in COHORTS.items():
        if counts[cohort]["total"] != expected_count:
            raise ValueError(f"Unexpected cohort count for {cohort}")

    return {
        "classification": "POST_HOC_DEVELOPMENT_REPLAY_NOT_NEW_BEHAVIORAL_EVIDENCE",
        "source_commit": SOURCE_COMMIT,
        "source_blob": SOURCE_BLOB,
        "model_inference_performed": False,
        "model_identity_verified": False,
        "dataset_is_unseen": False,
        "treatment_changed": "Recorded model selection accepted only if present in the recorded metadata lexical candidate list",
        "cohorts": {key: dict(value) for key, value in counts.items()},
        "vetoed": vetoed,
        "limits": [
            "Rule was formulated after inspecting these outcomes: selection bias and overfitting risk.",
            "The model originally saw metadata candidates if present, otherwise a SQLite fallback.",
            "Model errors and invalid or empty responses are UNKNOWN, not valid abstentions.",
            "This recipe-selection experiment does not measure general prompt explore relevance.",
            "Re-running these labels is a reproducibility check, not independent model improvement.",
        ],
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()

    evidence, sha256 = read_pinned_evidence()
    report = evaluate(evidence)
    report["source_sha256"] = sha256
    positives = report["cohorts"]["blind_positive"]
    hard = report["cohorts"]["blind_hard_negative"]
    outside = report["cohorts"]["blind_out_of_catalog"]

    # Frozen observations, not a pass/fail quality threshold for any future cohort.
    if not (
        positives["model_correct_selection"] == 7
        and positives["veto_correct_selection"] == 7
        and hard["model_explicit_none"] == 8
        and outside["model_wrong_selection"] == 3
        and outside["veto_wrong_selection"] == 1
        and outside["withheld_selection"] == 2
        and outside["model_explicit_none"] == 2
        and outside["invalid_or_empty_model_response"] == 3
    ):
        raise ValueError("Frozen retrospective counts changed; investigate source/logic")

    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({
        "source": SOURCE_COMMIT,
        "positive_correct_before_after": [positives["model_correct_selection"], positives["veto_correct_selection"]],
        "hard_negative_explicit_none": hard["model_explicit_none"],
        "out_of_catalog_wrong_before_after": [outside["model_wrong_selection"], outside["veto_wrong_selection"]],
        "out_of_catalog_invalid_or_empty": outside["invalid_or_empty_model_response"],
        "classification": report["classification"],
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
