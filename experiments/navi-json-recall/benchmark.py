#!/usr/bin/env python3
"""Independent, preregistered retrieval comparison; JSON remains authoritative.

No LLM; no claims about agent accuracy. This experimental module is not shipped.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import runpy
import sqlite3
import statistics
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Any, Callable

BUDGETS = (2048, 8192)
COHORTS = ("exact_id", "english_paraphrase", "german_paraphrase", "unanswerable")
EXPECTED_COUNTS = (4, 16, 6, 4)


def select_bounded(
    identifiers: list[str],
    encoded: dict[str, bytes],
    byte_budget: int,
) -> list[str]:
    """Accept complete source-bound records only; stop before crossing budget."""
    chosen: list[str] = []
    consumed = 0
    for identity in identifiers:
        entry = encoded[identity]
        cost = len(entry) + (2 if chosen else 0)
        if consumed + cost > byte_budget:
            break
        chosen.append(identity)
        consumed += cost
    return chosen


def query_tokens(query: str) -> list[str]:
    """Generate safe, quoted OR terms for SQLite FTS5 Unicode tokenizer."""
    words = re.findall(r"[\w]+", query.casefold(), flags=re.UNICODE)
    return list(dict.fromkeys(w for w in words if len(w) >= 2))


def fts_rank(conn: sqlite3.Connection, query: str) -> list[str]:
    tokens = query_tokens(query)
    if not tokens:
        return []
    expression = " OR ".join('"' + token.replace('"', '""') + '"' for token in tokens)
    rows = conn.execute(
        "SELECT identity FROM prompts WHERE prompts MATCH ? ORDER BY bm25(prompts), identity",
        (expression,),
    )
    return [str(row[0]) for row in rows]


def build_fts(source: dict[str, str]) -> sqlite3.Connection:
    conn = sqlite3.connect(":memory:")
    conn.execute("CREATE VIRTUAL TABLE prompts USING fts5(identity UNINDEXED, body, tokenize='unicode61')")
    conn.executemany(
        "INSERT INTO prompts (identity, body) VALUES (?, ?)",
        [(identity, identity.replace("-", " ") + " " + template)
         for identity, template in source.items()],
    )
    return conn


def evaluate(ids: list[str], target: str | None) -> dict[str, bool]:
    if target is None:
        return {"correct_top1": not ids, "correct_at_3": not ids, "abstained": not ids}
    return {
        "correct_top1": bool(ids) and ids[0] == target,
        "correct_at_3": target in ids[:3],
        "abstained": not ids,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--binary", required=True, type=Path)
    parser.add_argument("--source", required=True, type=Path)
    parser.add_argument("--queries", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    options = parser.parse_args()
    options.output.parent.mkdir(parents=True, exist_ok=True)
    native = runpy.run_path(str(Path(__file__).with_name("probe.py")))
    source_file = options.source.resolve(strict=True)
    fixture_file = options.queries.resolve(strict=True)
    source_sha, indexed = native["canonical_source"](source_file)
    fixture_bytes = fixture_file.read_bytes()
    fixture = json.loads(fixture_bytes)
    assert fixture["schema_version"] == "1.0"
    cases = fixture["queries"]
    assert isinstance(cases, list)
    assert [sum(int(x["cohort"] == category) for x in cases) for category in COHORTS] == list(EXPECTED_COUNTS)
    assert len(set((x["cohort"], x["query"]) for x in cases)) == len(cases)
    assert all(x["expected_id"] is None or x["expected_id"] in indexed for x in cases)

    content = {identity: native["envelope"](identity, source_sha, template)
               for identity, template in indexed.items()}
    fts = build_fts(indexed)
    report: dict[str, Any] = {
        "status": "fail",
        "source_sha256": source_sha,
        "queries_sha256": hashlib.sha256(fixture_bytes).hexdigest(),
        "native_upstream_sha": native["UPSTREAM_SHA"],
        "index_entries": len(indexed),
        "conditions": ["native_activate", "sqlite_fts5", "json_exact_id"],
        "byte_budgets": BUDGETS,
        "no_llm": True,
        "cases": [],
    }
    try:
        with tempfile.TemporaryDirectory(prefix="native-json-benchmark-") as root:
            base = Path(root)
            store, sock = base / "store", base / "native.sock"
            binary = options.binary.resolve(strict=True)
            subprocess.run([str(binary), "init", str(store)],
                           check=True, timeout=15, capture_output=True)
            with (base / "daemon.log").open("wb") as log:
                daemon = native["daemon_start"](binary, store, sock, log)
                try:
                    for identity, value in content.items():
                        key = hashlib.sha256(b"benchmark\0" + source_sha.encode()
                                             + b"\0" + identity.encode()).hexdigest()
                        returned_id, returned_bytes = native["native_create"](sock, key, value)
                        if returned_id <= 0 or returned_bytes != value:
                            raise AssertionError("Benchmark index cannot reproduce source")
                    for case in cases:
                        query = case["query"]
                        expected = case["expected_id"]
                        if not isinstance(query, str) or not query.strip():
                            raise ValueError("Empty preregistered query")
                        encoded_query = query.encode("utf-8")
                        # Native uses its own tokenizer. The byte filter below is
                        # identical for all methods, but their internal costs differ.
                        start = time.perf_counter_ns()
                        activated = native["wire"](
                            sock, f"ACTIVATE 4096 {len(encoded_query)}", encoded_query
                        )
                        native_ids = [
                            item["id"]
                            for item in native["validated_activation_candidates"](activated, source_file)
                        ]
                        native_ns = time.perf_counter_ns() - start

                        start = time.perf_counter_ns()
                        fts_ids = fts_rank(fts, query)
                        fts_ns = time.perf_counter_ns() - start

                        start = time.perf_counter_ns()
                        direct_ids = [query] if query in indexed else []
                        direct_ns = time.perf_counter_ns() - start
                        all_results = {
                            "native_activate": (native_ids, native_ns),
                            "sqlite_fts5": (fts_ids, fts_ns),
                            "json_exact_id": (direct_ids, direct_ns),
                        }
                        metrics: dict[str, Any] = {}
                        for name, (candidates, elapsed_ns) in all_results.items():
                            scores: dict[str, Any] = {
                                "query_latency_ms": round(elapsed_ns / 1e6, 5),
                                "raw_candidates": len(candidates),
                            }
                            for budget in BUDGETS:
                                trimmed = select_bounded(candidates, content, budget)
                                measures = evaluate(trimmed, expected)
                                scores[str(budget)] = {
                                    "ids": trimmed,
                                    "output_bytes": sum(len(content[i]) for i in trimmed) + max(0, len(trimmed)-1)*2,
                                    **measures,
                                }
                                if scores[str(budget)]["output_bytes"] > budget:
                                    raise AssertionError("Context-budget contract broken")
                            metrics[name] = scores
                        report["cases"].append({
                            "cohort": case["cohort"],
                            "query": query,
                            "expected_id": expected,
                            "methods": metrics,
                        })
                finally:
                    native["daemon_close"](daemon, sock)
            result = subprocess.run([str(binary), "verify", str(store)],
                                    capture_output=True, timeout=15)
            if result.returncode != 0:
                raise AssertionError("Native post-benchmark integrity failed")

        summary: dict[str, Any] = {}
        for method in report["conditions"]:
            method_scores: dict[str, Any] = {}
            for budget in BUDGETS:
                key = str(budget)
                rows = [(c, c["methods"][method][key]) for c in report["cases"]]
                cohort_metrics: dict[str, Any] = {}
                for cohort in COHORTS:
                    samples = [r for c, r in rows if c["cohort"] == cohort]
                    cohort_metrics[cohort] = {
                        "queries": len(samples),
                        "top1_correct": sum(int(r["correct_top1"]) for r in samples),
                        "at3_correct": sum(int(r["correct_at_3"]) for r in samples),
                        "mean_context_bytes": round(statistics.mean(r["output_bytes"] for r in samples), 2),
                    }
                method_scores[key] = cohort_metrics
            latencies = [c["methods"][method]["query_latency_ms"] for c in report["cases"]]
            summary[method] = {
                "by_budget": method_scores,
                "mean_warm_query_ms": round(statistics.mean(latencies), 5),
                "max_warm_query_ms": max(latencies),
            }
        report["summary"] = summary
        report["status"] = "pass"
        print("Native vs SQLite-FTS5 vs direct JSON: observational comparison")
        for name, info in summary.items():
            print(name, "mean_ms", info["mean_warm_query_ms"],
                  "scores_at_8192", {k:(v["top1_correct"], v["at3_correct"])
                                     for k,v in info["by_budget"]["8192"].items()})
    except Exception as exc:
        report["error"] = f"{type(exc).__name__}: {exc}"
        raise
    finally:
        options.output.write_text(json.dumps(report, indent=2, sort_keys=True,
                                             ensure_ascii=False) + "\n", encoding="utf-8")
        print("Evidence:", options.output)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
