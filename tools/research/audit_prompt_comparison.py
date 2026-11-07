#!/usr/bin/env python3
"""Audit raw research responses without conflating labels with semantic accuracy."""

from __future__ import annotations

import collections
import hashlib
import json
import pathlib
import re
import sys
from typing import Any

ARMS = ("explore", "next-step", "future-work-project")
SCENARIOS = ("R1", "R2", "R3", "R4", "N1", "N2", "N3")
LABELS = {
    "explore": ("EXPLORE", "NOTHING_TO_EXPLORE"),
    "next-step": (
        "CONTINUE_AUTHORIZED", "TEST_HYPOTHESIS", "PIVOT_APPROACH",
        "PREPARE_FOLLOW_UP", "REQUEST_DECISION", "NO_USEFUL_NEXT_STEP",
    ),
    "future-work-project": (
        "NOW_WORTH_PREPARING", "FOLLOW_UP", "BLOCKED",
        "NOT_WORTH_IT", "NO_FURTHER_INVESTMENT",
    ),
}


def sha256(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def read_object(path: pathlib.Path) -> dict[str, Any]:
    result = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(result, dict):
        raise ValueError(f"Expected object: {path}")
    return result


def main(directory: pathlib.Path) -> int:
    manifest = read_object(directory / "manifest.json")
    raw_fixtures = (directory / "fixtures.json").read_bytes()
    if sha256(raw_fixtures) != manifest["fixture_sha256"]:
        raise ValueError("Fixture digest mismatch")
    fixture = json.loads(raw_fixtures)
    actual_ids = tuple(item["id"] for item in fixture["scenarios"])
    if actual_ids != SCENARIOS or len(set(actual_ids)) != len(SCENARIOS):
        raise ValueError("Scenario identifiers or order changed")

    prompts = read_object(directory / "prompts.json")
    if set(prompts) != set(ARMS):
        raise ValueError("Unexpected prompt arms")
    for arm in ARMS:
        if sha256(prompts[arm].encode("utf-8")) != manifest["prompt_sha256"][arm]:
            raise ValueError(f"Prompt digest mismatch: {arm}")

    rows = [
        json.loads(line) for line in
        (directory / "responses.jsonl").read_text(encoding="utf-8").splitlines()
        if line.strip()
    ]
    repeats = manifest["repeats"]
    expected = {
        (scenario, arm, repeat)
        for scenario in SCENARIOS for arm in ARMS for repeat in range(repeats)
    }
    observed: set[tuple[str, str, int]] = set()
    counts: dict[str, collections.Counter[str]] = {
        arm: collections.Counter() for arm in ARMS
    }
    negatives: dict[str, collections.Counter[str]] = {
        arm: collections.Counter() for arm in ARMS
    }
    rows_with_multiple_labels = []
    invalid = []
    for row in rows:
        key = (row["scenario"], row["arm"], row["repeat"])
        if key in observed or key not in expected:
            raise ValueError(f"Duplicate or unexpected scenario/arm/repeat: {key}")
        observed.add(key)
        if row.get("status") != "ok" or not isinstance(row.get("content"), str):
            invalid.append({"key": key, "status": row.get("status")})
            continue
        content = row["content"]
        # Whole-word labels, ignoring Markdown emphasis. No semantic inference here.
        found = [
            label for label in LABELS[row["arm"]]
            if re.search(r"(?<![A-Z_])" + re.escape(label) + r"(?![A-Z_])", content)
        ]
        leading = re.match(
            r"\s*(?:[#*>_-]+\s*)*(?:\*\*)?([A-Z_]{3,})(?:\*\*)?(?=\s|:|$)",
            content,
        )
        first = leading.group(1) if leading and leading.group(1) in LABELS[row["arm"]] else "UNRECOGNIZED"
        counts[row["arm"]][first] += 1
        if row["scenario"] in ("N1", "N2"):
            negatives[row["arm"]][first] += 1
        if len(found) > 1:
            rows_with_multiple_labels.append({
                "scenario": key[0], "arm": key[1], "repeat": key[2],
                "labels": found,
            })
    if observed != expected:
        raise ValueError(f"Missing inference records: {sorted(expected - observed)}")

    report: dict[str, Any] = {
        "basis": "mechanical label inspection only, NOT blinded semantic evaluation",
        "complete": len(rows) == len(expected) and not invalid,
        "total_records": len(rows),
        "transport_errors": invalid,
        "counts_by_arm": {
            arm: dict(sorted(counts[arm].items())) for arm in ARMS
        },
        "negative_controls_n1_n2": {
            arm: dict(sorted(negatives[arm].items())) for arm in ARMS
        },
        "multiple_disposition_labels": rows_with_multiple_labels,
        "adjudication": "not_performed",
        "generalizability": "none: synthetic cases, single local model, no blinded semantic grading",
    }
    (directory / "mechanical-audit.json").write_text(
        json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    print(json.dumps(report, indent=2, sort_keys=True))
    return 1 if invalid else 0


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: audit_prompt_comparison.py <evidence-directory>")
    try:
        sys.exit(main(pathlib.Path(sys.argv[1])))
    except (OSError, ValueError, KeyError, TypeError) as error:
        print(f"Audit failed: {type(error).__name__}: {error}", file=sys.stderr)
        sys.exit(2)
