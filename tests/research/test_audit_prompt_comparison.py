#!/usr/bin/env python3
"""Regression tests for experimental evidence audit; no model is required."""

from __future__ import annotations

import importlib.util
import pathlib
import unittest

MODULE = pathlib.Path(__file__).resolve().parents[2] / "tools/research/audit_prompt_comparison.py"
SPEC = importlib.util.spec_from_file_location("audit_prompt_comparison", MODULE)
assert SPEC is not None and SPEC.loader is not None
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


class AuditPromptComparisonTest(unittest.TestCase):
    def test_leading_disposition(self) -> None:
        self.assertEqual("EXPLORE", audit.declared_disposition("explore", "EXPLORE\nQuestion: ..."))
        self.assertEqual("NOTHING_TO_EXPLORE", audit.declared_disposition("explore", "**NOTHING_TO_EXPLORE**"))
        self.assertEqual("UNRECOGNIZED", audit.declared_disposition("explore", "No decision yet."))

    def test_markdown_classification(self) -> None:
        self.assertEqual(
            "NOT_WORTH_IT",
            audit.declared_disposition("future-work-project", "**Classification:** `NOT_WORTH_IT`"),
        )
        self.assertEqual(
            "NOW_WORTH_PREPARING",
            audit.declared_disposition("future-work-project", "**Classification:** `NOW_WORTH_PREPARING`"),
        )

    def test_action_section(self) -> None:
        self.assertEqual(
            "PREPARE_FOLLOW_UP",
            audit.declared_disposition(
                "next-step",
                "**Smallest Bounded Next Step:**\n**PREPARE_FOLLOW_UP**\nInvestigate.",
            ),
        )

    def test_quoted_other_labels_are_not_disposition(self) -> None:
        self.assertEqual(
            "NOTHING_TO_EXPLORE",
            audit.declared_disposition(
                "explore", "NOTHING_TO_EXPLORE\nDo not emit EXPLORE without evidence.",
            ),
        )
        self.assertEqual(
            ["EXPLORE", "NOTHING_TO_EXPLORE"],
            audit.labels_mentioned(
                "explore", "NOTHING_TO_EXPLORE\nDo not emit EXPLORE without evidence.",
            ),
        )

    def test_truncated_response_is_not_semantically_complete(self) -> None:
        self.assertTrue(audit.is_truncated({"status": "ok", "finish_reason": "length"}))
        self.assertFalse(audit.is_truncated({"status": "ok", "finish_reason": "stop"}))


if __name__ == "__main__":
    unittest.main()
