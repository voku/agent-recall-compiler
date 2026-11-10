#!/usr/bin/env python3
"""Evaluate the same real-host bounded choice with/without a source-consensus veto.

Research only. The test labels are predeclared, source-based researcher judgments,
not independent human adjudication. A green workflow means the receipt is
complete, not that either policy improved engineering outcomes.
"""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
from typing import Any

SOURCE_COMMIT = "b560fb6d09bc49d19cf8ff5154f44f141cbb0953"
SOURCE_SCRIPT = "experiments/navi-json-recall/benchmark.py"
SOURCE_SCRIPT_BLOB = "e5fca92842d247811eed62a05997ceefb04bc2e4"
CATALOG_COMMIT = "0d99e266c7bad88b2b0f402fe8a3b617e8c1bce0"
CATALOG = Path("resources/skills/agent-recall-consumer/operating-prompts.json")
METADATA = Path("resources/skills/agent-recall-consumer/operating-prompts.metadata.json")
FIXTURE = Path("research/issue-intent-choice-cohort.json")
BRIDGE = Path("tools/research/issue_choice_owner.php")
EXPECTED = {"workflow_requested": 5, "topic_only": 5}
ALLOWED_REFS = {
    "voku/php-runtime-audit#34",
    "voku/agent-loop#879",
    "voku/agent-loop#838",
    "voku/agent-loop#457",
    "voku/agent-ui#178",
}


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def pinned_git_file(commit: str, path: str) -> bytes:
    return subprocess.run(
        ["git", "show", f"{commit}:{path}"],
        capture_output=True, check=True,
    ).stdout


def load_retriever(temp: Path) -> Any:
    raw = pinned_git_file(SOURCE_COMMIT, SOURCE_SCRIPT)
    blob = hashlib.sha1(f"blob {len(raw)}\0".encode("ascii") + raw).hexdigest()
    if blob != SOURCE_SCRIPT_BLOB:
        raise RuntimeError("Pinned experimental retrieval implementation changed")
    saved = temp / "benchmark.py"
    saved.write_bytes(raw)
    spec = importlib.util.spec_from_file_location("frozen_benchmark", saved)
    if spec is None or spec.loader is None:
        raise RuntimeError("Cannot import pinned retrieval implementation")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def validate_cohort() -> list[dict[str, Any]]:
    raw = FIXTURE.read_bytes()
    doc = json.loads(raw)
    if doc.get("schema_version") != "1.0" or doc.get("status") != "FROZEN_BEFORE_MODEL_INFERENCE":
        raise ValueError("Invalid prospective cohort")
    if doc.get("attempted_model_calls") != 10 or doc.get("labels_independently_adjudicated") is not False:
        raise ValueError("Unexpected model budget or evidence classification")
    if doc.get("catalog_ref") != CATALOG_COMMIT or doc.get("retrieval_implementation_ref") != SOURCE_COMMIT:
        raise ValueError("Unpinned owner/retrieval reference")
    cases = doc.get("cases")
    if not isinstance(cases, list) or len(cases) != 10:
        raise ValueError("Expected ten preregistered cases")
    counts = {key: 0 for key in EXPECTED}
    ids: set[str] = set()
    for case in cases:
        if not isinstance(case, dict) or not isinstance(case.get("id"), str):
            raise ValueError("Malformed case")
        if case["id"] in ids or case.get("source_ref") not in ALLOWED_REFS:
            raise ValueError("Duplicate case or unsupported source")
        ids.add(case["id"])
        cohort = case.get("cohort")
        if cohort not in counts:
            raise ValueError("Unknown cohort")
        counts[cohort] += 1
        if not isinstance(case.get("query"), str) or len(case["query"].strip()) < 40:
            raise ValueError("Empty or too short human-authored task")
        if not isinstance(case.get("label_basis"), str):
            raise ValueError("Missing human label rationale")
        if cohort == "workflow_requested" and not isinstance(case.get("expected_id"), str):
            raise ValueError("Positive must state expected recipe")
        if cohort == "topic_only" and case.get("expected_id") is not None:
            raise ValueError("Topic-only control must have null expected recipe")
    if counts != EXPECTED:
        raise ValueError(f"Predeclared cohort count mismatch: {counts}")
    return cases


def owner(mode: str, payload: dict[str, Any]) -> dict[str, Any]:
    call = subprocess.run(
        ["php", str(BRIDGE), mode],
        input=json.dumps(payload, ensure_ascii=False),
        capture_output=True, text=True, check=True, timeout=15,
    )
    result = json.loads(call.stdout)
    if not isinstance(result, dict):
        raise ValueError("PHP owner returned no JSON object")
    return result


def parse_copilot_events(stdout: str) -> tuple[str, list[str], int]:
    messages: list[str] = []
    chunks: list[str] = []
    models: set[str] = set()
    tool_attempts = 0
    count = 0
    for line in stdout.splitlines():
        if not line.strip():
            continue
        event = json.loads(line)
        if not isinstance(event, dict) or not isinstance(event.get("type"), str):
            raise ValueError("Malformed Copilot event")
        count += 1
        data = event.get("data")
        if not isinstance(data, dict):
            data = {}
        model = data.get("model") or data.get("modelId")
        if isinstance(model, str) and model:
            models.add(model)
        kind = event["type"]
        if kind in ("tool.execution_start", "tool_call"):
            tool_attempts += 1
        if kind == "assistant.message":
            content = data.get("content")
            if isinstance(content, str) and content.strip():
                messages.append(content)
        elif kind in ("assistant.message_delta", "agent_message_chunk"):
            content = data.get("deltaContent") or data.get("delta") or event.get("text")
            if isinstance(content, str):
                chunks.append(content)
    if count == 0:
        raise ValueError("No Copilot JSONL evidence")
    raw = messages[-1] if messages else "".join(chunks)
    if not raw.strip():
        raise ValueError("No final model output in Copilot stream")
    return raw.strip(), sorted(models), tool_attempts


def infer(prompt: str, case_id: str, root: Path) -> dict[str, Any]:
    base = root / case_id
    base.mkdir(parents=True, exist_ok=False)
    home = base / "copilot-home"
    home.mkdir()
    env = dict(os.environ)
    env["COPILOT_HOME"] = str(home)
    env["COPILOT_AUTO_UPDATE"] = "false"
    # Only the job provides GITHUB_TOKEN. Never include it in receipts or logs.
    cmd = [
        "copilot", "-p", prompt,
        "--output-format=json", "--no-ask-user", "--trust-folder",
        "--model", "auto",
        "--deny-tool=read,write,url,memory,shell",
    ]
    try:
        completed = subprocess.run(
            cmd, cwd=base, capture_output=True, text=True, env=env, timeout=110,
        )
    except subprocess.TimeoutExpired:
        return {"status": "model_error", "error_class": "timeout", "model_ids": [], "tool_attempts": 0}
    if completed.returncode != 0:
        return {
            "status": "model_error",
            "error_class": "copilot_exit_nonzero",
            "exit_code": completed.returncode,
            "model_ids": [],
            "tool_attempts": 0,
        }
    if len(completed.stdout.encode("utf-8")) > 131072:
        return {"status": "model_error", "error_class": "oversized_stream", "model_ids": [], "tool_attempts": 0}
    try:
        response, models, attempts = parse_copilot_events(completed.stdout)
    except (ValueError, json.JSONDecodeError):
        return {"status": "model_error", "error_class": "invalid_host_events", "model_ids": [], "tool_attempts": 0}
    if attempts > 0:
        return {"status": "model_error", "error_class": "unexpected_tool_attempt", "model_ids": models, "tool_attempts": attempts}
    return {"status": "response", "raw_response": response, "model_ids": models, "tool_attempts": attempts}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["preflight", "infer"])
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--enforce-workflow-applicability", action="store_true")
    args = parser.parse_args()

    cases = validate_cohort()
    for path in (CATALOG, METADATA):
        frozen = pinned_git_file(CATALOG_COMMIT, str(path))
        if frozen != path.read_bytes():
            raise RuntimeError(f"Canonical owner file changed since cohort freeze: {path}")
    manifest = json.loads(CATALOG.read_bytes())
    if manifest.get("schema_version") != "1.0":
        raise ValueError("Unsupported owner catalog")
    source = {x["id"]: x["template"] for x in manifest["prompts"]}
    if len(source) != len(manifest["prompts"]):
        raise ValueError("Duplicate owner recipe")

    with tempfile.TemporaryDirectory(prefix="choice-issue-") as scratch:
        scratch_path = Path(scratch)
        retrieval = load_retriever(scratch_path)
        metadata_db, metadata = retrieval.build_metadata_fts(METADATA, source)
        fts_db = retrieval.build_fts(source)
        try:
            records: list[dict[str, Any]] = []
            for case in cases:
                query = case["query"]
                metadata_ids = retrieval.metadata_bounded_rank(metadata_db, metadata, source, query)[:3]
                fallback_ids = retrieval.fts_rank(fts_db, query)[:3]
                offered = metadata_ids if metadata_ids else fallback_ids
                if any(x not in source for x in offered):
                    raise ValueError("Retrieval candidate missing from owner JSON")
                expected = case["expected_id"]
                if expected is not None and expected not in source:
                    raise ValueError("Gold recipe does not belong to current owner")
                candidate_projection = [
                    {
                        "id": identity,
                        "title": metadata[identity]["title"],
                        "description": metadata[identity]["description"],
                        "purpose": metadata[identity]["purpose"],
                    }
                    for identity in offered
                ]
                prompt = owner("prompt", {
                    "intent": query,
                    "candidates": candidate_projection,
                    "enforce_workflow_applicability": args.enforce_workflow_applicability,
                })["prompt"]
                marker_present = "WORKFLOW_APPLICABILITY_EXPERIMENT" in prompt
                if marker_present != args.enforce_workflow_applicability:
                    raise RuntimeError("Actual PHP owner prompt intervention differs from requested arm")
                record: dict[str, Any] = {
                    "case_id": case["id"],
                    "source_ref": case["source_ref"],
                    "cohort": case["cohort"],
                    "expected_id": expected,
                    "metadata_ids": metadata_ids,
                    "fallback_ids": fallback_ids,
                    "offered_ids": offered,
                    "prompt_sha256": digest(prompt.encode("utf-8")),
                    "candidate_availability": expected in offered if expected is not None else None,
                }
                if args.mode == "infer":
                    inference = infer(prompt, case["id"], scratch_path)
                    record["host"] = {key: value for key, value in inference.items() if key != "raw_response"}
                    if inference["status"] == "response":
                        raw = inference["raw_response"]
                        outcome = owner("validate", {"raw_response": raw, "candidate_ids": offered})
                        choice = outcome["selected"]
                        baseline = outcome["status"]
                        # Never turn an empty or malformed model response into an abstention.
                        vetoed = baseline == "selected" and choice not in metadata_ids
                        accepted = None if vetoed else choice
                        record.update({
                            "raw_model_output": raw[:2048],
                            "raw_model_output_sha256": digest(raw.encode("utf-8")),
                            "baseline_status": baseline,
                            "baseline_choice": choice,
                            "source_veto": vetoed,
                            "veto_status": "withheld_by_source" if vetoed else baseline,
                            "veto_choice": accepted,
                        })
                    else:
                        record["baseline_status"] = "model_error"
                        record["veto_status"] = "model_error"
                        record["baseline_choice"] = None
                        record["veto_choice"] = None
                        record["source_veto"] = False
                records.append(record)

            summary: dict[str, Any] = {
                "schema_version": "1.0",
                "evidence_class": "REAL_HOST_EXPLORATORY_NOT_INDEPENDENT_HUMAN_ADJUDICATION" if args.mode == "infer" else "FROZEN_FIXTURE_PREFLIGHT_NO_MODEL",
                "cohort_sha256": digest(FIXTURE.read_bytes()),
                "catalog_sha256": digest(CATALOG.read_bytes()),
                "metadata_sha256": digest(METADATA.read_bytes()),
                "retrieval_commit": SOURCE_COMMIT,
                "retrieval_blob_sha": SOURCE_SCRIPT_BLOB,
                "model_policy": "Copilot CLI 1.0.107 --model auto (backend identity not guaranteed)",
                "prompt_arm": "opt_in_workflow_applicability" if args.enforce_workflow_applicability else "unchanged_default_owner",
                "paired_baseline_exact_head": "f5f00b9c74dc1e0fc17d5d60424e2ad1c3b810a6" if args.enforce_workflow_applicability else None,
                "labels_independently_adjudicated": False,
                "model_calls_expected": len(records) if args.mode == "infer" else 0,
                "cases": records,
            }
            if args.mode == "infer":
                summary["counts"] = {
                    "model_errors": sum(r["baseline_status"] == "model_error" for r in records),
                    "invalid_outputs": sum(r["baseline_status"] == "invalid_output" for r in records),
                    "explicit_none": sum(r["baseline_status"] == "explicit_none" for r in records),
                    "correct_positives_baseline": sum(r["expected_id"] is not None and r["baseline_choice"] == r["expected_id"] for r in records),
                    "correct_positives_veto": sum(r["expected_id"] is not None and r["veto_choice"] == r["expected_id"] for r in records),
                    "false_topic_selections_baseline": sum(r["expected_id"] is None and r["baseline_choice"] is not None for r in records),
                    "false_topic_selections_veto": sum(r["expected_id"] is None and r["veto_choice"] is not None for r in records),
                    "correct_positives_withheld": sum(r["expected_id"] is not None and r["baseline_choice"] == r["expected_id"] and r["source_veto"] for r in records),
                    "source_vetoes": sum(r["source_veto"] for r in records),
                }
                summary["classification"] = (
                    "INCOMPLETE_MODEL_RECEIPTS"
                    if summary["counts"]["model_errors"] > 0 else
                    "POST_INFERENCE_RESEARCH_NO_HUMAN_ADJUDICATION"
                )

            args.output.parent.mkdir(parents=True, exist_ok=True)
            args.output.write_text(json.dumps(summary, sort_keys=True, indent=2) + "\n", encoding="utf-8")
            print(json.dumps({key: value for key, value in summary.items() if key not in ("cases",)}, indent=2))
            if args.mode == "infer" and summary["counts"]["model_errors"]:
                raise RuntimeError("Model failure(s): evidence retained; incomplete experiment")
            return 0
        finally:
            metadata_db.close()
            fts_db.close()


if __name__ == "__main__":
    raise SystemExit(main())
