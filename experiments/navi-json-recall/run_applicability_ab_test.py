#!/usr/bin/env python3
"""Run A/B evaluation between Default Prompt vs. Workflow Applicability Contract v0.

Compares model selection on paired cases (topic-only vs workflow-demanded)
using identical candidate pools and live model inference.
"""

from __future__ import annotations

import json
import re
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Any

import benchmark


def validate_choice(raw: str, candidates: list[str]) -> str | None:
    text = raw.strip()
    if not text or len(text) > 1024:
        return None
    try:
        data = json.loads(text)
    except Exception:
        return None
    if not isinstance(data, dict) or list(data.keys()) != ["selected"]:
        return None
    sel = data["selected"]
    if not isinstance(sel, str) or sel == "none":
        return None
    match = re.fullmatch(r"cand_(0|[1-9][0-9]*)", sel)
    if not match:
        return None
    idx = int(match.group(1))
    if idx < 0 or idx >= len(candidates):
        return None
    return candidates[idx]


def build_prompt(
    query: str,
    candidates: list[str],
    meta_by_id: dict[str, Any],
    enforce_workflow_applicability: bool = False,
) -> str:
    lines = [
        "You are an authoritative candidate relevance reviewer.",
        "A coding agent needs to select an applicable software engineering recipe for the given task intent.",
        "",
        "## Task Intent",
        query.strip(),
        "",
        "## Candidate Recipes",
    ]
    for idx, cid in enumerate(candidates):
        m = meta_by_id.get(cid, {})
        purpose = f" (purpose: {m.get('purpose', '')})" if m.get("purpose") else ""
        lines.append(f"[cand_{idx}] id: \"{cid}\"{purpose}")
        lines.append(f"   title: {m.get('title', cid)}")
        lines.append(f"   description: {m.get('description', '')}")

    lines.append("[none] None of the candidates above apply to this software engineering task.")
    lines.append("")
    lines.append("## Instructions")
    lines.append("1. Review the task intent against the candidate recipes.")
    lines.append(
        "2. If the task is unrelated to software engineering (e.g. non-software requests, personal chores) or none of the candidates match, select \"none\"."
    )

    if enforce_workflow_applicability:
        lines.append("")
        lines.append("WORKFLOW_APPLICABILITY_EXPERIMENT")
        lines.append(
            "Only choose a recipe when its operational steps materially match the requested workflow or method, not merely because the task uses related technical vocabulary."
        )
        lines.append(
            "A generic process recipe may apply across languages and stacks when the user actually requests that process; an ordinary implementation task alone does not imply any generic recipe is needed."
        )
        lines.append(
            "If none of the offered recipes matches the requested workflow or method, select \"none\". This does not mean the underlying task is impossible."
        )
        lines.append(
            "Do not infer missing workflow instructions from superficial topic similarities, invent candidate IDs, or grant execution authority."
        )

    lines.append("3. Respond ONLY with a single JSON object in this exact format:")
    lines.append('{"selected": "cand_<index>"} or {"selected": "none"}')
    lines.append("Do not include any explanation or extra text.")
    return "\n".join(lines)


def main() -> int:
    root = Path(__file__).resolve().parents[2]
    source_path = root / "resources/skills/agent-recall-consumer/operating-prompts.json"
    metadata_path = root / "resources/skills/agent-recall-consumer/operating-prompts.metadata.json"
    queries_path = Path(__file__).parent / "applicability_ab_queries.json"
    output_path = Path(__file__).parent / "evidence/applicability-ab-benchmark.json"

    with source_path.open(encoding="utf-8") as f:
        source_data = json.load(f)
    indexed = {p["id"]: p["template"] for p in source_data["prompts"]}

    conn, meta_by_id = benchmark.build_metadata_fts(metadata_path, indexed)

    with queries_path.open(encoding="utf-8") as f:
        data = json.load(f)
    pairs = data["pairs"]

    agy_bin = shutil.which("agy")
    if not agy_bin:
        print("ERROR: agy binary not found in PATH", file=sys.stderr)
        return 1

    evaluated_cases = []

    print("================ RUNNING APPLICABILITY A/B BENCHMARK ================")

    for pair in pairs:
        pair_id = pair["pair_id"]
        target_recipe = pair["target_recipe"]
        print(f"\n--- PAIR: {pair_id} (target: {target_recipe}) ---")

        for case in pair["cases"]:
            query = case["query"]
            role = case["variant_role"]
            exp_legacy = case["expected_legacy"]
            exp_contract = case["expected_contract"]

            # FTS5 candidates
            fts_cands = benchmark.metadata_bounded_rank(conn, meta_by_id, indexed, query)[:3]
            # Ensure the target recipe is present in candidate set so both models can be tested fairly
            if target_recipe not in fts_cands:
                cands = [target_recipe] + [c for c in fts_cands if c != target_recipe][:2]
            else:
                cands = fts_cands

            # Variant A (Default / Legacy prompt)
            prompt_a = build_prompt(query, cands, meta_by_id, enforce_workflow_applicability=False)
            proc_a = subprocess.run([agy_bin, "--print", prompt_a], capture_output=True, text=True, timeout=30)
            raw_a = proc_a.stdout.strip()
            sel_a = validate_choice(raw_a, cands)

            # Variant B (Applicability Contract prompt)
            prompt_b = build_prompt(query, cands, meta_by_id, enforce_workflow_applicability=True)
            proc_b = subprocess.run([agy_bin, "--print", prompt_b], capture_output=True, text=True, timeout=30)
            raw_b = proc_b.stdout.strip()
            sel_b = validate_choice(raw_b, cands)

            eval_entry = {
                "pair_id": pair_id,
                "variant_role": role,
                "query": query,
                "candidates_offered": cands,
                "expected_legacy": exp_legacy,
                "expected_contract": exp_contract,
                "variant_a_legacy": {
                    "raw": raw_a,
                    "selected": sel_a,
                    "correct_vs_contract": (sel_a == exp_contract),
                },
                "variant_b_contract": {
                    "raw": raw_b,
                    "selected": sel_b,
                    "correct_vs_contract": (sel_b == exp_contract),
                },
            }
            evaluated_cases.append(eval_entry)

            print(f"[{role:17s}] Q: {query[:65]}...")
            print(f"   Variant A (Legacy)  : selected: {str(sel_a):28s} | raw: {raw_a}")
            print(f"   Variant B (Contract): selected: {str(sel_b):28s} | raw: {raw_b}")
            print(f"   Expected (Contract) : {str(exp_contract)}")

    output_path.parent.mkdir(parents=True, exist_ok=True)
    report = {
        "status": "success",
        "benchmark": "Applicability Contract v0 A/B evaluation",
        "case_count": len(evaluated_cases),
        "cases": evaluated_cases,
    }
    with output_path.open("w", encoding="utf-8") as f:
        json.dump(report, f, indent=2)

    print(f"\nSaved A/B benchmark evidence to {output_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
