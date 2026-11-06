#!/usr/bin/env python3
"""Run JSON/SQLite recall ablations without installing or starting native tokmem.

Shares candidate ranking and byte-budget functions with benchmark.py.
No network access, model access, or workflow state mutations are needed.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import statistics
import time
from pathlib import Path
from typing import Any

import benchmark
import probe


METHODS = (
    "json_exact_id",
    "sqlite_fts5",
    "json_first_fts5",
    "metadata_bounded_choice",
    "metadata_coverage_gate_0_6",
)


def read_cases(original: Path, challenge: Path, indexed: dict[str, str]) -> tuple[list[dict[str, Any]], dict[str, str]]:
    original_bytes = original.read_bytes()
    challenge_bytes = challenge.read_bytes()
    original_data = json.loads(original_bytes)
    challenge_data = json.loads(challenge_bytes)
    if not isinstance(original_data, dict) or original_data.get("schema_version") != "1.0":
        raise ValueError("Invalid original query schema")
    if not isinstance(challenge_data, dict) or challenge_data.get("schema_version") != "1.0":
        raise ValueError("Invalid challenge query schema")
    main_cases = original_data.get("queries")
    challenge_cases = challenge_data.get("cases")
    if not isinstance(main_cases, list) or not isinstance(challenge_cases, list):
        raise ValueError("Invalid query fixtures")
    if [
        sum(isinstance(x, dict) and x.get("cohort") == cohort for x in main_cases)
        for cohort in benchmark.COHORTS[:4]
    ] != list(benchmark.EXPECTED_COUNTS):
        raise ValueError("Original query cohort mismatch")
    if [
        sum(isinstance(x, dict) and x.get("cohort") == cohort for x in challenge_cases)
        for cohort in benchmark.COHORTS[4:]
    ] != [8, 8]:
        raise ValueError("Challenge cohort mismatch")

    cases = [*main_cases, *challenge_cases]
    seen: set[tuple[str, str]] = set()
    for case in cases:
        if not isinstance(case, dict):
            raise ValueError("Non-object query")
        cohort, query, target = case.get("cohort"), case.get("query"), case.get("expected_id")
        if cohort not in benchmark.COHORTS or not isinstance(query, str) or not query.strip():
            raise ValueError("Invalid query cohort or text")
        if target is not None and (not isinstance(target, str) or target not in indexed):
            raise ValueError("Unknown expected identity")
        if cohort in ("unanswerable", "hard_unanswerable") and target is not None:
            raise ValueError("Negative query has a positive label")
        if cohort == "agent_loop_issue":
            ref = case.get("issue")
            if not isinstance(ref, str) or not ref.startswith("voku/agent-loop#"):
                raise ValueError("Real-issue label must contain an owner issue reference")
        key = (cohort, query)
        if key in seen:
            raise ValueError("Duplicate query")
        seen.add(key)
    return cases, {
        "queries_sha256": hashlib.sha256(original_bytes).hexdigest(),
        "issue_challenge_sha256": hashlib.sha256(challenge_bytes).hexdigest(),
    }


def evaluate(
    source_path: Path,
    metadata_path: Path,
    original: Path,
    challenge: Path,
) -> dict[str, Any]:
    """Read-only experiment. No native socket, process or binary parameter exists."""
    source_sha256, indexed = probe.canonical_source(source_path)
    cases, digests = read_cases(original, challenge, indexed)
    # A missing, changed or malformed metadata catalog must fail before scoring.
    meta_fts, meta_by_id = benchmark.build_metadata_fts(metadata_path, indexed)
    plain_fts = benchmark.build_fts(indexed)
    try:
        content = {
            identity: probe.envelope(identity, source_sha256, template)
            for identity, template in indexed.items()
        }
        report: dict[str, Any] = {
            "status": "pass",
            "mode": "standalone-readonly-no-native",
            "source_sha256": source_sha256,
            "metadata_sha256": hashlib.sha256(metadata_path.read_bytes()).hexdigest(),
            **digests,
            "case_count": len(cases),
            "methods": list(METHODS),
            "byte_budgets": list(benchmark.BUDGETS),
            "cases": [],
        }
        for case in cases:
            query = case["query"]
            start = time.perf_counter_ns()
            raw_fts = benchmark.fts_rank(plain_fts, query)
            fts_ms = (time.perf_counter_ns() - start) / 1e6

            start = time.perf_counter_ns()
            metadata_ids = benchmark.metadata_bounded_rank(
                meta_fts, meta_by_id, indexed, query
            )
            metadata_ms = (time.perf_counter_ns() - start) / 1e6

            start = time.perf_counter_ns()
            coverage_ids = benchmark.metadata_bounded_rank(
                meta_fts, meta_by_id, indexed, query, min_query_coverage=0.6
            )
            coverage_ms = (time.perf_counter_ns() - start) / 1e6

            exact = [query] if query in indexed else []
            candidates: dict[str, tuple[list[str], float]] = {
                "json_exact_id": (exact, 0.0),
                "sqlite_fts5": (raw_fts, fts_ms),
                "json_first_fts5": (exact if exact else raw_fts, fts_ms),
                "metadata_bounded_choice": (metadata_ids, metadata_ms),
                "metadata_coverage_gate_0_6": (coverage_ids, coverage_ms),
            }
            method_evidence: dict[str, Any] = {}
            for method, (identities, latency_ms) in candidates.items():
                metrics: dict[str, Any] = {
                    "latency_ms": round(latency_ms, 5),
                    "raw_candidates": len(identities),
                }
                for budget in benchmark.BUDGETS:
                    chosen = benchmark.select_bounded(identities, content, budget)
                    delivered_bytes = sum(len(content[x]) for x in chosen)
                    delivered_bytes += max(0, len(chosen) - 1) * 2
                    if delivered_bytes > budget:
                        raise AssertionError("Output exceeds byte budget")
                    metrics[str(budget)] = {
                        "ids": chosen,
                        "output_bytes": delivered_bytes,
                        "budget_censored_all_candidates": bool(identities and not chosen),
                        **benchmark.evaluate(chosen, case["expected_id"], identities),
                    }
                method_evidence[method] = metrics
            report["cases"].append({
                "cohort": case["cohort"],
                "query": query,
                "expected_id": case["expected_id"],
                "issue": case.get("issue"),
                "methods": method_evidence,
            })

        report["summary"] = {}
        for method in METHODS:
            report["summary"][method] = {}
            for budget in benchmark.BUDGETS:
                by_cohort: dict[str, dict[str, int]] = {}
                for cohort in benchmark.COHORTS:
                    selected = [
                        x["methods"][method][str(budget)]
                        for x in report["cases"] if x["cohort"] == cohort
                    ]
                    by_cohort[cohort] = {
                        "count": len(selected),
                        "top1_correct": sum(int(x["correct_top1"]) for x in selected),
                        "top3_correct": sum(int(x["correct_at_3"]) for x in selected),
                        "abstentions": sum(int(x["abstained"]) for x in selected),
                    }
                report["summary"][method][str(budget)] = by_cohort
        # A conservative baseline invariant: exact IDs never become fuzzy matches.
        for case in report["cases"]:
            if case["cohort"] == "exact_id":
                got = case["methods"]["json_exact_id"][str(max(benchmark.BUDGETS))]["ids"]
                if got != [case["expected_id"]]:
                    raise AssertionError("Canonical exact-identity baseline regressed")
        return report
    finally:
        meta_fts.close()
        plain_fts.close()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True, type=Path)
    parser.add_argument("--metadata", required=True, type=Path)
    parser.add_argument("--queries", required=True, type=Path)
    parser.add_argument("--challenge", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    if args.source.is_symlink() or args.metadata.is_symlink():
        raise ValueError("Canonical JSON files cannot be symlinks")
    report = evaluate(args.source, args.metadata, args.queries, args.challenge)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    print("Offline JSON/SQLite baseline: " + str(report["case_count"]) + " queries")
    for method in METHODS:
        metrics = report["summary"][method][str(max(benchmark.BUDGETS))]
        print(method, {
            cohort: (values["top1_correct"], values["top3_correct"])
            for cohort, values in metrics.items()
        })
    print("Result: " + report["status"] + ", no tokmem process required")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
