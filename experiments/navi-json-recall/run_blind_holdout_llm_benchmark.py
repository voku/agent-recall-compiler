#!/usr/bin/env python3
"""Independent Blind Holdout Benchmark with Live LLM Bounded Choice Evaluation.

Evaluates candidate selection on completely held-out queries verified to have
ZERO exact or subphrase overlap with any registered alias or intent metadata.
"""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
import time
from pathlib import Path
from typing import Any

import benchmark
from bounded_choice_wire import is_explicit_none_response, validate_bounded_choice_response


def check_zero_contamination(
    cases: list[dict[str, Any]],
    meta_by_id: dict[str, Any],
) -> None:
    """Ensure ZERO exact phrase or subphrase overlap with registered aliases/intents."""
    registered: set[str] = set()
    for recipe in meta_by_id.values():
        for alias in recipe.get("aliases", []):
            registered.add(" ".join(alias.casefold().split()))
        for intent in recipe.get("intents", []):
            registered.add(" ".join(intent.casefold().split()))

    overlaps: list[tuple[str, str, str]] = []
    for case in cases:
        query = " ".join(case["query"].casefold().split())
        for reg in registered:
            if query == reg:
                overlaps.append(("exact", case["query"], reg))
            elif reg in query or query in reg:
                overlaps.append(("subphrase", case["query"], reg))

    if overlaps:
        raise ValueError(f"Contamination detected in blind holdout dataset: {overlaps}")


def call_live_llm(prompt: str, timeout_seconds: int = 30) -> str:
    """Execute live model inference using the local agy CLI."""
    agy_bin = shutil.which("agy")
    if not agy_bin:
        raise RuntimeError("agy binary not found in PATH")

    cmd = [agy_bin, "--print", prompt]
    proc = subprocess.run(
        cmd,
        capture_output=True,
        text=True,
        timeout=timeout_seconds,
        check=True,
    )
    return proc.stdout.strip()


def build_bounded_prompt(query: str, candidates: list[str], meta_by_id: dict[str, Any]) -> str:
    """Mirror PHP BoundedChoicePromptBuilder."""
    lines = [
        "You are an authoritative candidate relevance reviewer.",
        "A coding agent needs to select an applicable software engineering recipe for the given task intent.",
        "",
        "## Task Intent",
        query.strip(),
        "",
        "## Candidate Recipes",
    ]
    for idx, cand_id in enumerate(candidates):
        m = meta_by_id.get(cand_id, {})
        purpose = f" (purpose: {m.get('purpose', '')})" if m.get("purpose") else ""
        lines.append(f"[cand_{idx}] id: \"{cand_id}\"{purpose}")
        lines.append(f"   title: {m.get('title', cand_id)}")
        lines.append(f"   description: {m.get('description', '')}")

    lines.append("[none] None of the candidates above apply to this software engineering task.")
    lines.append("")
    lines.append("## Instructions")
    lines.append("1. Review the task intent against the candidate recipes.")
    lines.append("2. If the task is unrelated to software engineering (e.g. non-software requests, personal chores) or none of the candidates match, select \"none\".")
    lines.append("3. Respond ONLY with a single JSON object in this exact format:")
    lines.append('{"selected": "cand_<index>"} or {"selected": "none"}')
    lines.append("Do not include any explanation or extra text.")
    return "\n".join(lines)


def evaluate_blind_holdout(
    source_path: Path,
    metadata_path: Path,
    queries_path: Path,
    output_path: Path | None = None,
    run_llm: bool = True,
) -> dict[str, Any]:
    with source_path.open(encoding="utf-8") as f:
        source_data = json.load(f)
    indexed = {p["id"]: p["template"] for p in source_data["prompts"]}

    meta_conn, meta_by_id = benchmark.build_metadata_fts(metadata_path, indexed)
    plain_conn = benchmark.build_fts(indexed)

    with queries_path.open(encoding="utf-8") as f:
        query_data = json.load(f)
    cases = query_data["queries"]

    check_zero_contamination(cases, meta_by_id)

    results: list[dict[str, Any]] = []
    cohort_stats: dict[str, dict[str, Any]] = {}

    methods = ["sqlite_fts5", "metadata_lexical", "metadata_60pct_gate"]
    if run_llm:
        methods.append("live_llm_bounded_choice")

    for method in methods:
        cohort_stats[method] = {
            "blind_positive": {"total": 0, "top1_correct": 0, "abstentions": 0, "invalid_outputs": 0, "model_errors": 0},
            "blind_hard_negative": {"total": 0, "top1_correct": 0, "abstentions": 0, "invalid_outputs": 0, "model_errors": 0},
            "blind_out_of_catalog": {"total": 0, "top1_correct": 0, "abstentions": 0, "invalid_outputs": 0, "model_errors": 0},
        }

    for case_idx, case in enumerate(cases):
        cohort = case["cohort"]
        query = case["query"]
        expected_id = case["expected_id"]

        # Plain FTS5
        raw_fts = benchmark.fts_rank(plain_conn, query)[:3]

        # Metadata FTS5
        meta_ranked = benchmark.metadata_bounded_rank(meta_conn, meta_by_id, indexed, query)[:3]

        # Metadata 60% coverage gate
        cov_ranked = benchmark.metadata_bounded_rank(
            meta_conn, meta_by_id, indexed, query, min_query_coverage=0.6
        )[:3]

        case_eval: dict[str, Any] = {
            "query": query,
            "cohort": cohort,
            "expected_id": expected_id,
            "predictions": {
                "sqlite_fts5": raw_fts,
                "metadata_lexical": meta_ranked,
                "metadata_60pct_gate": cov_ranked,
            },
        }

        # Update non-LLM stats
        for m_name, preds in [
            ("sqlite_fts5", raw_fts),
            ("metadata_lexical", meta_ranked),
            ("metadata_60pct_gate", cov_ranked),
        ]:
            cohort_stats[m_name][cohort]["total"] += 1
            if not preds:
                cohort_stats[m_name][cohort]["abstentions"] += 1
                if expected_id is None:
                    cohort_stats[m_name][cohort]["top1_correct"] += 1
            else:
                if preds[0] == expected_id:
                    cohort_stats[m_name][cohort]["top1_correct"] += 1

        # Live LLM evaluation
        if run_llm:
            # Present top candidates from metadata; if empty, supply plain FTS distractors
            cand_pool = meta_ranked if meta_ranked else raw_fts
            prompt = build_bounded_prompt(query, cand_pool, meta_by_id)
            model_error: str | None = None
            try:
                raw_llm = call_live_llm(prompt)
            except Exception as e:
                # Transport errors are not model output and cannot count as abstentions.
                raw_llm = ""
                model_error = type(e).__name__

            llm_choice = (
                validate_bounded_choice_response(raw_llm, cand_pool)
                if model_error is None
                else None
            )
            explicit_none = model_error is None and is_explicit_none_response(raw_llm)
            response_status = (
                "model_error" if model_error is not None else
                "selected" if llm_choice is not None else
                "explicit_none" if explicit_none else
                "invalid_output"
            )

            case_eval["predictions"]["live_llm_bounded_choice"] = [llm_choice] if llm_choice else []
            case_eval["llm_raw_response"] = raw_llm
            case_eval["llm_candidates_offered"] = cand_pool
            case_eval["model_error"] = model_error
            case_eval["response_status"] = response_status

            metrics = cohort_stats["live_llm_bounded_choice"][cohort]
            metrics["total"] += 1
            if llm_choice is not None:
                if llm_choice == expected_id:
                    metrics["top1_correct"] += 1
            elif explicit_none:
                metrics["abstentions"] += 1
                if expected_id is None:
                    metrics["top1_correct"] += 1
            elif model_error is not None:
                metrics["model_errors"] += 1
            else:
                metrics["invalid_outputs"] += 1

            print(
                f"[{case_idx+1:02d}/{len(cases):02d}] {cohort:22s} | expected: {str(expected_id):25s} | LLM choice: {str(llm_choice):25s} | raw: {raw_llm}"
            )

        results.append(case_eval)

    report = {
        "status": "success",
        "contamination_check": "PASS (0 exact matches, 0 subphrase matches)",
        "case_count": len(cases),
        "methods": methods,
        "cohort_summary": cohort_stats,
        "cases": results,
    }

    if output_path:
        output_path.parent.mkdir(parents=True, exist_ok=True)
        with output_path.open("w", encoding="utf-8") as f:
            json.dump(report, f, indent=2)

    return report


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--metadata", type=Path, required=True)
    parser.add_argument("--queries", type=Path, required=True)
    parser.add_argument("--output", type=Path, default=None)
    parser.add_argument("--no-llm", action="store_true")
    args = parser.parse_args()

    report = evaluate_blind_holdout(
        source_path=args.source,
        metadata_path=args.metadata,
        queries_path=args.queries,
        output_path=args.output,
        run_llm=not args.no_llm,
    )

    print("\n================ BLIND HOLDOUT BENCHMARK SUMMARY ================")
    summary = report["cohort_summary"]
    for method, cohorts in summary.items():
        print(f"\n--- Method: {method} ---")
        for cname, stats in cohorts.items():
            tot = stats["total"]
            corr = stats["top1_correct"]
            ab = stats["abstentions"]
            pct = (corr / tot * 100) if tot else 0.0
            print(f"  {cname:24s}: {corr}/{tot} correct ({pct:5.1f}%) | abstentions: {ab}/{tot} | invalid: {stats['invalid_outputs']}/{tot} | errors: {stats['model_errors']}/{tot}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
