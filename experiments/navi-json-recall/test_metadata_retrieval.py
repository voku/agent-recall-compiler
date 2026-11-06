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

    def test_enrichment_ablation_disables_alias_only_matching(self) -> None:
        self.metadata["recipes"][0]["aliases"] = ["verify oddball repository workflow"]
        self.save_metadata()
        enriched, enriched_meta = benchmark.build_metadata_fts(self.path, self.source)
        without, without_meta = benchmark.build_metadata_fts(
            self.path, self.source, include_enrichment=False,
        )
        self.addCleanup(enriched.close)
        self.addCleanup(without.close)
        query = "oddball repository workflow"
        self.assertEqual(
            ["rule-scope"],
            benchmark.metadata_bounded_rank(enriched, enriched_meta, self.source, query),
        )
        self.assertEqual(
            [],
            benchmark.metadata_bounded_rank(without, without_meta, self.source, query),
        )

    def test_no_model_never_claims_a_bounded_choice(self) -> None:
        candidates = ["rule-scope", "rule-test"]
        metadata_by_id = {recipe["id"]: recipe for recipe in self.metadata["recipes"]}
        self.assertEqual(
            [],
            benchmark.bounded_choice_rerank("review source code", candidates, metadata_by_id, None),
        )
        self.assertEqual(
            ["rule-test"],
            benchmark.bounded_choice_rerank(
                "review source code", candidates, metadata_by_id, lambda query, choices: "rule-test",
            ),
        )
        self.assertEqual(
            [],
            benchmark.bounded_choice_rerank(
                "review source code", candidates, metadata_by_id, lambda query, choices: "hallucinated",
            ),
        )
        self.assertEqual(
            [],
            benchmark.bounded_choice_rerank(
                "review source code", candidates, metadata_by_id, lambda query, choices: "none",
            ),
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

    def test_holdout_no_exact_or_subphrase_overlap_against_real_owner_catalog(self) -> None:
        # Validate the real checked-in catalog, never the two-recipe setUp stub.
        root = Path(__file__).resolve().parents[2]
        blind_file = Path(__file__).parent / "blind_holdout_queries.json"
        metadata_file = (
            root / "resources/skills/agent-recall-consumer/operating-prompts.metadata.json"
        )
        source_file = root / "resources/skills/agent-recall-consumer/operating-prompts.json"
        blind = json.loads(blind_file.read_text(encoding="utf-8"))
        catalog = json.loads(metadata_file.read_text(encoding="utf-8"))
        source = json.loads(source_file.read_text(encoding="utf-8"))
        self.assertEqual(26, len(blind["queries"]))
        self.assertEqual(
            {"blind_positive": 10, "blind_hard_negative": 8, "blind_out_of_catalog": 8},
            {cohort: sum(case["cohort"] == cohort for case in blind["queries"])
             for cohort in ("blind_positive", "blind_hard_negative", "blind_out_of_catalog")},
        )
        real_ids = {item["id"] for item in source["prompts"]}
        self.assertEqual(real_ids, {item["id"] for item in catalog["recipes"]})
        registered_phrases = {
            " ".join(phrase.casefold().split())
            for recipe in catalog["recipes"]
            for phrase in [*recipe.get("aliases", []), *recipe.get("intents", [])]
            if phrase.strip()
        }
        self.assertTrue(registered_phrases)
        self.assertEqual(
            26, len({case["query"] for case in blind["queries"]}),
            "Blind queries must be distinct",
        )
        for case in blind["queries"]:
            query = " ".join(case["query"].casefold().split())
            if case["cohort"] == "blind_positive":
                self.assertIn(case["expected_id"], real_ids)
            else:
                self.assertIsNone(case["expected_id"])
            for phrase in registered_phrases:
                self.assertNotEqual(query, phrase)
                self.assertNotIn(
                    phrase, query,
                    "Full alias/intent phrase overlaps blind query: " + phrase,
                )
                self.assertNotIn(
                    query, phrase,
                    "Blind query is contained in registered phrase: " + query,
                )

    def test_agent_loop_project_benchmark_has_zero_contamination_against_real_catalog(self) -> None:
        root = Path(__file__).resolve().parents[2]
        proj_file = Path(__file__).parent / "agent_loop_project_queries.json"
        if not proj_file.is_file():
            self.skipTest("agent_loop_project_queries.json not present")
        metadata_file = (
            root / "resources/skills/agent-recall-consumer/operating-prompts.metadata.json"
        )
        source_file = root / "resources/skills/agent-recall-consumer/operating-prompts.json"
        proj_data = json.loads(proj_file.read_text(encoding="utf-8"))
        catalog = json.loads(metadata_file.read_text(encoding="utf-8"))
        source = json.loads(source_file.read_text(encoding="utf-8"))
        real_ids = {item["id"] for item in source["prompts"]}
        registered_phrases = {
            " ".join(phrase.casefold().split())
            for recipe in catalog["recipes"]
            for phrase in [*recipe.get("aliases", []), *recipe.get("intents", [])]
            if phrase.strip()
        }
        self.assertEqual(18, len(proj_data["queries"]))
        for case in proj_data["queries"]:
            query = " ".join(case["query"].casefold().split())
            if case["cohort"] == "project_positive":
                self.assertIn(case["expected_id"], real_ids)
            else:
                self.assertIsNone(case["expected_id"])
            for phrase in registered_phrases:
                self.assertNotEqual(query, phrase)
                self.assertNotIn(
                    phrase, query,
                    "Alias/intent phrase overlaps project query: " + phrase,
                )
                self.assertNotIn(
                    query, phrase,
                    "Project query is contained in registered phrase: " + query,
                )


if __name__ == "__main__":
    unittest.main()
