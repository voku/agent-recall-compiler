#!/usr/bin/env python3
"""Behavioral regression tests for experimental metadata candidate retrieval."""

from __future__ import annotations

import importlib.util
import json
import sqlite3
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).with_name("benchmark.py")
SPEC = importlib.util.spec_from_file_location("native_json_benchmark", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Cannot locate benchmark source")
benchmark = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(benchmark)


class MetadataRetrievalContractTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="metadata-recall-unit-")
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / "metadata.json"
        self.source = {
            "rule-scope": "Patch code after source validation.",
            "rule-test": "Write a failing regression test before changes.",
        }
        self.metadata = {
            "schema_version": "1.0",
            "recipes": [
                {
                    "id": "rule-scope",
                    "title": "Patch on demand",
                    "description": "Patching tasks with source verification.",
                    "purpose": "review",
                    "arguments": [],
                },
                {
                    "id": "rule-test",
                    "title": "Reproduce first",
                    "description": "Reproduce a bug with regression tests.",
                    "purpose": "test",
                    "arguments": [],
                },
            ],
        }
        self.save_metadata()

    def save_metadata(self) -> None:
        self.path.write_text(json.dumps(self.metadata), encoding="utf-8")

    def test_exact_identity_keeps_first_priority(self) -> None:
        conn, meta = benchmark.build_metadata_fts(self.path, self.source)
        self.addCleanup(conn.close)
        self.assertEqual(
            ["rule-scope"],
            benchmark.metadata_bounded_rank(conn, meta, self.source, "rule-scope"),
        )

    def test_substring_of_matched_word_is_not_second_word_evidence(self) -> None:
        conn, meta = benchmark.build_metadata_fts(self.path, self.source)
        self.addCleanup(conn.close)
        # "atching" is only a substring of "patching"; "nonexistent" is absent.
        # Previously the query got two fake votes: patch + atching.
        self.assertEqual(
            [],
            benchmark.metadata_bounded_rank(
                conn, meta, self.source, "patch atching nonexistent"
            ),
        )

    def test_optional_coverage_filter_rejects_sparse_generic_overlap(self) -> None:
        conn, meta = benchmark.build_metadata_fts(self.path, self.source)
        self.addCleanup(conn.close)
        query = "patch code unrelated exotic museum"
        self.assertEqual(
            ["rule-scope"],
            benchmark.metadata_bounded_rank(conn, meta, self.source, query),
        )
        self.assertEqual(
            [],
            benchmark.metadata_bounded_rank(
                conn, meta, self.source, query, min_query_coverage=0.6,
            ),
        )
        with self.assertRaisesRegex(ValueError, "coverage"):
            benchmark.metadata_bounded_rank(
                conn, meta, self.source, query, min_query_coverage=1.5,
            )

    def test_stop_word_only_query_is_not_a_search_intent(self) -> None:
        conn, meta = benchmark.build_metadata_fts(self.path, self.source)
        self.addCleanup(conn.close)
        self.assertEqual(
            [],
            benchmark.metadata_bounded_rank(conn, meta, self.source, "the and for"),
        )

    def test_sqlite_error_is_not_disguised_as_a_valid_empty_selection(self) -> None:
        conn = sqlite3.connect(":memory:")
        self.addCleanup(conn.close)
        with self.assertRaisesRegex(RuntimeError, "FTS5"):
            benchmark.metadata_bounded_rank(
                conn, self.metadata["recipes"][0:0], self.source,
                "patch code verification"
            )

    def test_missing_metadata_cannot_silently_fall_back_to_templates(self) -> None:
        self.path.unlink()
        with self.assertRaisesRegex((ValueError, RuntimeError), "metadata"):
            benchmark.build_metadata_fts(self.path, self.source)

    def test_unknown_metadata_identity_is_rejected(self) -> None:
        self.metadata["recipes"].append({
            "id": "unknown-rule",
            "title": "Unknown",
            "description": "Not in the authoritative source",
            "purpose": "test",
            "arguments": [],
        })
        self.save_metadata()
        with self.assertRaisesRegex((ValueError, RuntimeError), "identity"):
            benchmark.build_metadata_fts(self.path, self.source)

    def test_missing_metadata_identity_is_rejected(self) -> None:
        self.metadata["recipes"].pop()
        self.save_metadata()
        with self.assertRaisesRegex((ValueError, RuntimeError), "identity"):
            benchmark.build_metadata_fts(self.path, self.source)

    def test_duplicate_metadata_identity_is_rejected(self) -> None:
        self.metadata["recipes"].append(dict(self.metadata["recipes"][0]))
        self.save_metadata()
        with self.assertRaisesRegex((ValueError, RuntimeError), "duplicate"):
            benchmark.build_metadata_fts(self.path, self.source)

    def test_empty_or_untrusted_schema_is_rejected(self) -> None:
        self.metadata["schema_version"] = "9.9"
        self.save_metadata()
        with self.assertRaisesRegex((ValueError, RuntimeError), "schema"):
            benchmark.build_metadata_fts(self.path, self.source)

    def test_budget_censorship_is_not_counted_as_true_abstention(self) -> None:
        self.assertFalse(
            benchmark.evaluate([], None, ["rule-scope"])["abstained"]
        )

    def test_output_budget_cannot_return_partial_evidence(self) -> None:
        self.assertEqual(
            [],
            benchmark.select_bounded(
                ["rule-scope"],
                {"rule-scope": b"record-needs-more-than-five-bytes"},
                5,
            ),
        )


if __name__ == "__main__":
    unittest.main()
