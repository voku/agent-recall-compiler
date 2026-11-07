#!/usr/bin/env python3
"""Regression tests: transport failures and invalid replies are not abstentions."""

from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from bounded_choice_wire import is_explicit_none_response
from replay_blind_holdout import replay
from run_blind_holdout_llm_benchmark import evaluate_blind_holdout


class BlindHoldoutAccountingTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.root = Path(__file__).resolve().parents[2]
        cls.exp = cls.root / "experiments/navi-json-recall"
        cls.source = cls.root / "resources/skills/agent-recall-consumer/operating-prompts.json"
        cls.metadata = cls.root / "resources/skills/agent-recall-consumer/operating-prompts.metadata.json"
        cls.queries = cls.exp / "blind_holdout_queries.json"
        cls.recorded = cls.exp / "evidence/blind-holdout-benchmark.json"

    def test_strict_explicit_none_is_distinct_from_invalid_or_empty(self) -> None:
        self.assertTrue(is_explicit_none_response('{"selected":"none"}'))
        self.assertTrue(is_explicit_none_response(' \n{"selected" : "none"}\r\n'))
        for raw in (
            "",
            "ERROR: timeout",
            '{"selected":"none","selected":"cand_0"}',
            '{"sele\\u0063ted":"none"}',
            '{"selected":"cand_0"}',
            '{"selected":"none"}' + " " * 1024,
        ):
            with self.subTest(raw=raw):
                self.assertFalse(is_explicit_none_response(raw))

    def test_recorded_negative_score_excludes_three_empty_outputs(self) -> None:
        report = replay(self.source, self.metadata, self.queries, self.recorded)
        self.assertEqual("local_recorded_model_output_replayed_not_live_ci_inference", report["evidence_class"])
        out = report["cohorts"]["blind_out_of_catalog"]
        self.assertEqual(8, out["total"])
        self.assertEqual(2, out["recorded_abstention"])
        self.assertEqual(2, out["correct_explicit"])
        self.assertEqual(3, out["empty_response"])
        self.assertEqual(3, out["unselected_or_invalid_format"])
        self.assertEqual(0, out.get("execution_error", 0))
        self.assertEqual(8, report["cohorts"]["blind_hard_negative"]["correct_explicit"])
        self.assertEqual(7, report["cohorts"]["blind_positive"]["correct_explicit"])
        self.assertEqual(0, report["live_model_invocations_in_ci"])
        self.assertFalse(report["provider_model_identity_verified"])

    def test_checked_in_derived_summary_matches_current_raw_replay(self) -> None:
        data = json.loads(self.recorded.read_text(encoding="utf-8"))
        report = replay(self.source, self.metadata, self.queries, self.recorded)
        for name, stat in data["cohort_summary"]["live_llm_bounded_choice"].items():
            replayed = report["cohorts"][name]
            with self.subTest(cohort=name):
                self.assertEqual(stat["total"], replayed["total"])
                self.assertEqual(stat["top1_correct"], replayed["correct_explicit"])
                self.assertEqual(stat["abstentions"], replayed["explicit_none"])
                self.assertEqual(stat["invalid_outputs"], replayed.get("unselected_or_invalid_format", 0))
                self.assertEqual(stat["model_errors"], replayed.get("execution_error", 0))

    def test_changing_negative_none_to_empty_does_not_keep_credit(self) -> None:
        data = json.loads(self.recorded.read_text(encoding="utf-8"))
        cases = [
            row for row in data["cases"]
            if row["cohort"] == "blind_out_of_catalog"
            and is_explicit_none_response(row["llm_raw_response"])
        ]
        self.assertEqual(2, len(cases))
        cases[0]["llm_raw_response"] = ""
        # Both responses validate to None, so the stored prediction remains [].
        with tempfile.TemporaryDirectory(prefix="blind-replay-") as directory:
            path = Path(directory) / "modified.json"
            path.write_text(json.dumps(data), encoding="utf-8")
            outcome = replay(self.source, self.metadata, self.queries, path)
        out = outcome["cohorts"]["blind_out_of_catalog"]
        self.assertEqual(1, out["recorded_abstention"])
        self.assertEqual(1, out["correct_explicit"])
        self.assertEqual(4, out["empty_response"])
        self.assertEqual(4, out["unselected_or_invalid_format"])

    @patch("run_blind_holdout_llm_benchmark.call_live_llm")
    def test_live_transport_failure_is_not_a_semantic_none(self, call) -> None:
        call.side_effect = RuntimeError("provider unavailable")
        report = evaluate_blind_holdout(
            source_path=self.source,
            metadata_path=self.metadata,
            queries_path=self.queries,
            run_llm=True,
        )
        observed = report["cohort_summary"]["live_llm_bounded_choice"]
        self.assertEqual(26, sum(s["model_errors"] for s in observed.values()))
        self.assertEqual(0, sum(s["abstentions"] for s in observed.values()))
        self.assertEqual(0, sum(s["top1_correct"] for s in observed.values()))
        self.assertEqual(0, sum(s["invalid_outputs"] for s in observed.values()))
        self.assertEqual(26, call.call_count)
        self.assertTrue(all(row["response_status"] == "model_error" for row in report["cases"]))
        self.assertTrue(all(row["model_error"] == "RuntimeError" for row in report["cases"]))
        self.assertTrue(all(row["llm_raw_response"] == "" for row in report["cases"]))


if __name__ == "__main__":
    unittest.main()
