#!/usr/bin/env python3
"""Bounded candidate discovery ablation over the frozen 26-query development fixture.

This is a POST-HOC diagnostic, NOT a blind model benchmark. No label is used
to rank candidates, no new aliases are added, and no model runs in this job.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from collections import defaultdict
from pathlib import Path
from typing import Any

from benchmark import (
    build_fts,
    build_metadata_fts,
    fts_rank,
    metadata_bounded_rank,
)
from run_blind_holdout_llm_benchmark import check_zero_contamination

TOP_K = (3, 5, 6)
RRF_CONSTANT = 60


def reciprocal_rank_fusion(
    rankings: list[list[str]],
    *,
    limit: int,
    constant: int = RRF_CONSTANT,
) -> list[str]:
    """Score candidates from rank positions, with stable deterministic ties."""
    if limit < 1 or constant < 1:
        raise ValueError("RRF parameters must be positive")
    scores: dict[str, float] = defaultdict(float)
    for ranking in rankings:
        # Repeated IDs within a ranking must not boost a candidate.
        for position, identity in enumerate(dict.fromkeys(ranking), start=1):
            scores[identity] += 1.0 / (constant + position)
    return sorted(scores, key=lambda identity: (-scores[identity], identity))[:limit]


def ordered_union(rankings: list[list[str]], *, limit: int) -> list[str]:
    if limit < 1:
        raise ValueError("limit must be positive")
    return list(dict.fromkeys(identity for ranking in rankings for identity in ranking))[:limit]


def record_source_ids(path: Path) -> dict[str, str]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    if payload.get("schema_version") != "1.0":
        raise ValueError("Unexpected prompt source schema")
    records = payload["prompts"]
    if not isinstance(records, list):
        raise ValueError("Unexpected prompt source records")
    result: dict[str, str] = {}
    for record in records:
        identity, template = record["id"], record["template"]
        if not isinstance(identity, str) or identity in result or not isinstance(template, str):
            raise ValueError("Duplicate or malformed authoritative recipe")
        result[identity] = template
    return result


def evaluate(
    source: Path,
    metadata: Path,
    queries: Path,
) -> dict[str, Any]:
    indexed = record_source_ids(source)
    corpus = json.loads(queries.read_text(encoding="utf-8"))
    if corpus.get("schema_version") != "1.0" or len(corpus.get("queries", [])) != 26:
        raise ValueError("Unexpected preregistered fixture")
    cases = corpus["queries"]
    meta_db, meta = build_metadata_fts(metadata, indexed)
    raw_db = build_fts(indexed)
    try:
        check_zero_contamination(cases, meta)
        rows: list[dict[str, Any]] = []
        for case in cases:
            query = case["query"]
            expected_id = case["expected_id"]
            cohort = case["cohort"]
            if cohort not in ("blind_positive", "blind_hard_negative", "blind_out_of_catalog"):
                raise ValueError("Unknown cohort")
            if expected_id is not None and expected_id not in indexed:
                raise ValueError("Target absent from authoritative catalog")
            if not isinstance(query, str) or not query.strip():
                raise ValueError("Blank query")
            meta_all = metadata_bounded_rank(meta_db, meta, indexed, query)
            # fts_rank has no top-five limit; use up to 10 for fixed RRF input
            raw_all = fts_rank(raw_db, query)
            strategies = {
                "metadata_only": meta_all,
                "raw_fts_only": raw_all,
                "metadata_first_union": ordered_union([meta_all[:5], raw_all[:10]], limit=6),
                "rrf_60": reciprocal_rank_fusion([meta_all[:5], raw_all[:10]], limit=6),
                "union_pool_upper_bound": ordered_union([meta_all[:5], raw_all[:10]], limit=15),
            }
            for name, ranked in strategies.items():
                if len(ranked) != len(set(ranked)) or not set(ranked).issubset(indexed):
                    raise AssertionError("Untrusted candidate leaked into " + name)
            rows.append({
                "cohort": cohort,
                "query": query,
                "expected_id": expected_id,
                "strategies": {
                    name: {
                        "ids": rank,
                        "gold_in_top_k": {
                            str(k): expected_id in rank[:k] if expected_id is not None else None
                            for k in TOP_K
                        },
                        "exposure_in_top_k": {
                            str(k): min(k, len(rank))
                            for k in TOP_K
                        },
                    }
                    for name, rank in strategies.items()
                },
            })

        summary: dict[str, Any] = {}
        for method in rows[0]["strategies"]:
            summary[method] = {}
            for cohort in ("blind_positive", "blind_hard_negative", "blind_out_of_catalog"):
                selected = [r for r in rows if r["cohort"] == cohort]
                summary[method][cohort] = {
                    "total": len(selected),
                    "gold_in_top_k": {
                        str(k): (
                            sum(int(r["strategies"][method]["gold_in_top_k"][str(k)]) for r in selected)
                            if cohort == "blind_positive" else None
                        )
                        for k in TOP_K
                    },
                    "candidate_exposure_sum": {
                        str(k): sum(r["strategies"][method]["exposure_in_top_k"][str(k)] for r in selected)
                        for k in TOP_K
                    },
                    "zero_candidate_count": sum(not r["strategies"][method]["ids"] for r in selected),
                }
        return {
            "status": "pass",
            "evaluation_class": "post_hoc_candidate_discovery_ablation_no_model_or_new_gold_labels",
            "production_winner_declared": False,
            "no_model_invocation": True,
            "source_sha256": hashlib.sha256(source.read_bytes()).hexdigest(),
            "metadata_sha256": hashlib.sha256(metadata.read_bytes()).hexdigest(),
            "queries_sha256": hashlib.sha256(queries.read_bytes()).hexdigest(),
            "fixed_parameters": {"rrf_constant": RRF_CONSTANT, "top_k": TOP_K, "metadata_input_limit": 5, "raw_fts_input_limit": 10},
            "summary": summary,
            "cases": rows,
        }
    finally:
        meta_db.close()
        raw_db.close()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--metadata", type=Path, required=True)
    parser.add_argument("--queries", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    result = evaluate(args.source, args.metadata, args.queries)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2, ensure_ascii=False, sort_keys=True) + "\n", encoding="utf-8")
    print("POST-HOC candidate discovery ablation, source digests verified; no LLM")
    for name, cohorts in result["summary"].items():
        print(name,
              "positive gold@3/5/6:", cohorts["blind_positive"]["gold_in_top_k"],
              "negative exposures@3:", cohorts["blind_hard_negative"]["candidate_exposure_sum"]["3"],
              "out-of-catalog exposures@3:", cohorts["blind_out_of_catalog"]["candidate_exposure_sum"]["3"])
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
