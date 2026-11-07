#!/usr/bin/env python3
"""Regression tests for replay integrity; never substitute model outputs."""

from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from replay_applicability_ab import replay


class ApplicabilityABReplayTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.root = Path(__file__).resolve().parents[2]
        cls.exp = cls.root / "experiments/navi-json-recall"
        cls.source = cls.root / "resources/skills/agent-recall-consumer/operating-prompts.json"
        cls.meta = cls.root / "resources/skills/agent-recall-consumer/operating-prompts.metadata.json"
        cls.queries = cls.exp / "applicability_ab_queries.json"
        cls.recorded = cls.exp / "evidence/applicability-ab-benchmark.json"

    def test_real_owner_replay_separates_explicit_from_fail_closed(self) -> None:
        report = replay(self.source, self.meta, self.queries, self.recorded)
        self.assertEqual(5, report["pairs_checked"])
        self.assertEqual(10, report["cases_checked"])
        self.assertEqual(4, report["target_forced_into_candidates_cases"])
        self.assertEqual(8, report["metrics"]["legacy"]["correct_with_fail_closed"])
        self.assertEqual(6, report["metrics"]["legacy"]["correct_explicit"])
        self.assertEqual(10, report["metrics"]["contract"]["correct_explicit"])
        self.assertFalse(report["model_identity_verified"])
        self.assertEqual(0, report["live_model_calls_in_ci"])

    def mutated_file(self, report: dict) -> tuple[tempfile.TemporaryDirectory[str], Path]:
        tmp = tempfile.TemporaryDirectory(prefix="applicability-replay-")
        path = Path(tmp.name) / "tampered-evidence.json"
        path.write_text(json.dumps(report), encoding="utf-8")
        return tmp, path

    def test_forged_model_selection_is_rejected(self) -> None:
        evidence = json.loads(self.recorded.read_text(encoding="utf-8"))
        evidence["cases"][0]["variant_b_contract"]["selected"] = "multi-pass-correctness-simplify"
        tmp, path = self.mutated_file(evidence)
        self.addCleanup(tmp.cleanup)
        with self.assertRaisesRegex(ValueError, "Selection drift"):
            replay(self.source, self.meta, self.queries, path)

    def test_candidate_pool_drift_is_rejected(self) -> None:
        evidence = json.loads(self.recorded.read_text(encoding="utf-8"))
        evidence["cases"][0]["candidates_offered"] = ["made-up-recipe"]
        tmp, path = self.mutated_file(evidence)
        self.addCleanup(tmp.cleanup)
        with self.assertRaisesRegex(ValueError, "candidates drifted"):
            replay(self.source, self.meta, self.queries, path)

    def test_gold_label_drift_is_rejected(self) -> None:
        evidence = json.loads(self.recorded.read_text(encoding="utf-8"))
        evidence["cases"][1]["expected_contract"] = None
        tmp, path = self.mutated_file(evidence)
        self.addCleanup(tmp.cleanup)
        with self.assertRaisesRegex(ValueError, "label mismatch"):
            replay(self.source, self.meta, self.queries, path)


if __name__ == "__main__":
    unittest.main()
