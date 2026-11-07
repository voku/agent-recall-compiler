#!/usr/bin/env python3
"""Unit/owner tests for model-free, bounded candidate discovery ablations."""

from __future__ import annotations

import unittest
from pathlib import Path

from candidate_fusion_ablation import (
    evaluate,
    ordered_union,
    reciprocal_rank_fusion,
)


class CandidateFusionContractTest(unittest.TestCase):
    def test_rrf_uses_both_rankings_and_deterministic_ties(self) -> None:
        ranked = reciprocal_rank_fusion(
            [["a", "b"], ["b", "c"]], limit=3
        )
        self.assertEqual(["b", "a", "c"], ranked)

    def test_duplicate_candidate_in_one_source_does_not_count_twice(self) -> None:
        self.assertEqual(
            ["a", "b"],
            reciprocal_rank_fusion([["a", "a", "a", "b"]], limit=2),
        )

    def test_union_preserves_first_ranking_and_bounds_candidates(self) -> None:
        self.assertEqual(
            ["a", "b", "c"],
            ordered_union([["a", "b"], ["b", "c", "d"]], limit=3),
        )

    def test_invalid_limits_are_rejected_explicitly(self) -> None:
        with self.assertRaises(ValueError):
            reciprocal_rank_fusion([["a"]], limit=0)
        with self.assertRaises(ValueError):
            reciprocal_rank_fusion([["a"]], limit=3, constant=0)
        with self.assertRaises(ValueError):
            ordered_union([["a"]], limit=0)

    def test_real_owner_catalog_fusion_produces_only_authoritative_candidates(self) -> None:
        root = Path(__file__).resolve().parents[2]
        source = root / "resources/skills/agent-recall-consumer/operating-prompts.json"
        metadata = root / "resources/skills/agent-recall-consumer/operating-prompts.metadata.json"
        queries = root / "experiments/navi-json-recall/blind_holdout_queries.json"
        report = evaluate(source, metadata, queries)
        self.assertEqual("pass", report["status"])
        self.assertTrue(report["no_model_invocation"])
        self.assertFalse(report["production_winner_declared"])
        self.assertEqual(26, len(report["cases"]))
        self.assertEqual(10, report["summary"]["rrf_60"]["blind_positive"]["total"])
        self.assertEqual(8, report["summary"]["rrf_60"]["blind_hard_negative"]["total"])
        self.assertEqual(8, report["summary"]["rrf_60"]["blind_out_of_catalog"]["total"])
        for record in report["cases"]:
            self.assertLessEqual(len(record["strategies"]["rrf_60"]["ids"]), 6)
            self.assertLessEqual(len(record["strategies"]["metadata_first_union"]["ids"]), 6)


if __name__ == "__main__":
    unittest.main()
