#!/usr/bin/env python3
"""Check that local Python experiments honor PHP's bounded-choice wire grammar."""

from __future__ import annotations

import subprocess
import unittest
from unittest.mock import patch

from bounded_choice_wire import validate_bounded_choice_response
from run_applicability_ab_test import invoke_local_model, validate_choice
from run_blind_holdout_llm_benchmark import validate_bounded_choice_response as blind_validate


class BoundedChoiceWireParityTest(unittest.TestCase):
    def test_both_experiment_entrypoints_use_single_literal_grammar(self) -> None:
        ids = ["reproduce-before-fix", "deletion-first"]
        cases = [
            ('{"selected":"cand_0"}', "reproduce-before-fix"),
            ('{"selected":"cand_1"}', "deletion-first"),
            (' \n{"selected" : "none"}\r\n', None),
            ('{"selected":"none","selected":"cand_0"}', None),
            ('{"selected":"cand_0","selected":"none"}', None),
            ('{"sele\\u0063ted":"cand_0"}', None),
            ('{"selected":"ca\\u006ed_0"}', None),
            ('{"selected":"cand_00"}', None),
            ('{"selected":"cand_2"}', None),
            ('\u00a0{"selected":"cand_0"}', None),
            ('{"selected":"cand_0","extra":true}', None),
            ('{"selected":"cand_0"}' + " " * 1024, None),
        ]
        for raw, expected in cases:
            with self.subTest(raw=raw):
                self.assertEqual(expected, validate_bounded_choice_response(raw, ids))
                self.assertEqual(expected, validate_choice(raw, ids))
                self.assertEqual(expected, blind_validate(raw, ids))

    @patch("run_applicability_ab_test.subprocess.run")
    def test_cli_failures_are_not_empty_model_answers(self, run) -> None:
        run.side_effect = subprocess.TimeoutExpired(cmd=["agy"], timeout=30)
        self.assertEqual(("", "timeout"), invoke_local_model("agy", "prompt"))

        run.side_effect = subprocess.CalledProcessError(returncode=42, cmd=["agy"])
        self.assertEqual(("", "exit_42"), invoke_local_model("agy", "prompt"))

        run.side_effect = OSError("binary unavailable")
        self.assertEqual(("", "os_error_OSError"), invoke_local_model("agy", "prompt"))

        run.side_effect = None
        run.return_value = subprocess.CompletedProcess(["agy"], 0, "\n", "")
        self.assertEqual(("", None), invoke_local_model("agy", "prompt"))
        self.assertEqual(True, run.call_args.kwargs["check"])
        self.assertEqual(30, run.call_args.kwargs["timeout"])


if __name__ == "__main__":
    unittest.main()
