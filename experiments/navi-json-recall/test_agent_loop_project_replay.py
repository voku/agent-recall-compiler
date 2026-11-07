#!/usr/bin/env python3
"""Regression checks for real-source project evidence replay and tamper resistance."""

from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from replay_agent_loop_project import replay


class AgentLoopProjectReplayTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.root = Path(__file__).resolve().parents[2]
        cls.exp = cls.root / "experiments/navi-json-recall"
        cls.source = cls.root / "resources/skills/agent-recall-consumer/operating-prompts.json"
        cls.metadata = cls.root / "resources/skills/agent-recall-consumer/operating-prompts.metadata.json"
        cls.queries = cls.exp / "agent_loop_project_queries.json"
        cls.recorded = cls.exp / "evidence/agent-loop-project-benchmark.json"

    def run_modified(self, evidence: dict) -> None:
        with tempfile.TemporaryDirectory(prefix="recall-project-replay-") as tmp:
            file = Path(tmp) / "forged.json"
            file.write_text(json.dumps(evidence), encoding="utf-8")
            replay(self.source, self.metadata, self.queries, file)

    def test_recorded_project_outcomes_are_15_not_17_of_18(self) -> None:
        result = replay(self.source, self.metadata, self.queries, self.recorded)
        self.assertEqual(18, result["overall"]["total"])
        self.assertEqual(15, result["overall"]["correct_with_fail_closed"])
        self.assertEqual(14, result["overall"]["correct_explicit"])
        self.assertEqual(1, result["overall"]["empty"])
        self.assertEqual(7, result["cohorts"]["project_positive"]["correct_with_fail_closed"])
        self.assertEqual(8, result["cohorts"]["project_out_of_catalog"]["correct_explicit"] +
                         result["cohorts"]["hard_domain_negative"]["correct_explicit"])
        self.assertEqual(3, len(result["failures"]))
        self.assertFalse(result["model_identity_verified"])

    def test_mutated_gold_label_fails_closed(self) -> None:
        fixture = json.loads(self.recorded.read_text(encoding="utf-8"))
        fixture["cases"][0]["expected_id"] = "made-up-recipe"
        with self.assertRaisesRegex(ValueError, "expected_id differs"):
            self.run_modified(fixture)

    def test_mutated_candidate_list_fails_closed(self) -> None:
        fixture = json.loads(self.recorded.read_text(encoding="utf-8"))
        fixture["cases"][0]["llm_candidates_offered"] = ["other-unverified-id"]
        with self.assertRaisesRegex(ValueError, "Unverified or unbounded"):
            self.run_modified(fixture)

    def test_mutated_model_prediction_fails_closed(self) -> None:
        fixture = json.loads(self.recorded.read_text(encoding="utf-8"))
        fixture["cases"][0]["predictions"]["live_llm_bounded_choice"] = ["made-up-recipe"]
        with self.assertRaisesRegex(ValueError, "selection drift"):
            self.run_modified(fixture)


if __name__ == "__main__":
    unittest.main()
