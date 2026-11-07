#!/usr/bin/env python3
"""Record reproducible, unscored live-model prompt comparisons (research only)."""

from __future__ import annotations

import hashlib
import json
import os
import pathlib
import subprocess
import sys
import time
import urllib.error
import urllib.request
from typing import Any

BASELINE = pathlib.Path(os.environ["RECALL_BASELINE"])
OUTPUT = pathlib.Path(os.environ["EVAL_OUTPUT"])
FIXTURES = pathlib.Path("research/opportunity-scan-fixtures.json")
API = "http://127.0.0.1:8080"
ARMS: dict[str, tuple[str, ...]] = {
    "explore": ("explore",),
    "next-step": ("next-step",),
    "future-work-project": ("future-work", "--scope", "project"),
}
REPEATS = 2
MAX_TOKENS = 256


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def read_json(path: pathlib.Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise ValueError(f"Expected object: {path}")
    return value


def invoke(endpoint: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
    request = urllib.request.Request(
        API + endpoint,
        data=None if payload is None else json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="GET" if payload is None else "POST",
    )
    with urllib.request.urlopen(request, timeout=180) as response:
        value = json.loads(response.read().decode("utf-8"))
    if not isinstance(value, dict):
        raise ValueError("Unexpected local inference API response")
    return value


def render_prompt(*arguments: str) -> str:
    result = subprocess.run(
        ["php", str(BASELINE / "bin/agent-recall-compiler"), "prompt", *arguments],
        check=True,
        capture_output=True,
        text=True,
        timeout=30,
    )
    if result.stderr:
        raise RuntimeError("Unexpected prompt CLI stderr: " + result.stderr[:300])
    return result.stdout.rstrip("\n")


def main() -> int:
    OUTPUT.mkdir(parents=True, exist_ok=True)
    fixture_bytes = FIXTURES.read_bytes()
    fixtures = read_json(FIXTURES)
    scenarios = fixtures.get("scenarios")
    if not isinstance(scenarios, list) or len(scenarios) != 7:
        raise ValueError("Expected exactly seven predeclared scenarios")

    prompts = {name: render_prompt(*args) for name, args in ARMS.items()}
    model_list = invoke("/v1/models")
    models = model_list.get("data")
    if not isinstance(models, list) or len(models) != 1:
        raise ValueError("Expected exactly one local model")
    model_id = models[0]["id"]
    if not isinstance(model_id, str) or not model_id:
        raise ValueError("Invalid local model identifier")

    meta = {
        "experiment": "synthetic prompt-comparison; no scoring or claims of superiority",
        "baseline_commit": os.environ["RECALL_COMMIT"],
        "llama_cpp_commit": os.environ["LLAMA_COMMIT"],
        "model_repository_revision": os.environ["MODEL_REVISION"],
        "model_sha256": os.environ["MODEL_SHA256"],
        "fixture_sha256": sha256(fixture_bytes),
        "prompt_sha256": {
            name: sha256(value.encode("utf-8"))
            for name, value in prompts.items()
        },
        "model_id": model_id,
        "max_tokens": MAX_TOKENS,
        "temperature": 0.6,
        "repeats": REPEATS,
        "no_mutating_tools": True,
        "adjudication": "not_performed",
    }
    (OUTPUT / "manifest.json").write_text(
        json.dumps(meta, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    (OUTPUT / "prompts.json").write_text(
        json.dumps(prompts, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    (OUTPUT / "fixtures.json").write_bytes(fixture_bytes)

    errors = 0
    outcomes = 0
    with (OUTPUT / "responses.jsonl").open("w", encoding="utf-8") as stream:
        for repeat in range(REPEATS):
            for scenario in scenarios:
                scenario_id = scenario.get("id")
                context = scenario.get("context")
                if not isinstance(scenario_id, str) or not isinstance(context, str):
                    raise ValueError("Invalid scenario object")
                for arm, prompt in prompts.items():
                    seed = 20261107 + (repeat * 1000) + sum(
                        ord(char) for char in scenario_id + arm
                    )
                    payload = {
                        "model": model_id,
                        "messages": [
                            {"role": "system", "content": prompt},
                            {
                                "role": "user",
                                "content": (
                                    "Synthetic research fixture, not an actual repository claim. "
                                    "Use ONLY the evidence explicitly in the fixture. "
                                    "Do not call tools or execute code. /no_think\n\n"
                                    + context
                                ),
                            },
                        ],
                        "temperature": 0.6,
                        "seed": seed,
                        "max_tokens": MAX_TOKENS,
                        "stream": False,
                    }
                    row: dict[str, Any] = {
                        "scenario": scenario_id,
                        "arm": arm,
                        "repeat": repeat,
                        "seed": seed,
                        "input_sha256": sha256(
                            json.dumps(payload, sort_keys=True).encode("utf-8")
                        ),
                    }
                    start = time.monotonic()
                    try:
                        response = invoke("/v1/chat/completions", payload)
                        choices = response["choices"]
                        if not isinstance(choices, list) or len(choices) == 0:
                            raise ValueError("No model choices returned")
                        message = choices[0]["message"]
                        row["status"] = "ok"
                        row["content"] = message.get("content")
                        row["reasoning_content"] = message.get("reasoning_content")
                        row["finish_reason"] = choices[0].get("finish_reason")
                        row["usage"] = response.get("usage")
                    except (OSError, ValueError, KeyError, IndexError, TypeError) as error:
                        row["status"] = "error"
                        row["error"] = f"{type(error).__name__}: {error}"
                        errors += 1
                    row["duration_ms"] = round((time.monotonic() - start) * 1000)
                    stream.write(json.dumps(row, ensure_ascii=False) + "\n")
                    stream.flush()
                    outcomes += 1
                    print(
                        f"{outcomes}/42: {scenario_id} {arm} rep={repeat} "
                        f"status={row['status']}",
                        flush=True,
                    )
    print(f"Completed {outcomes} inference attempts; errors={errors}")
    return 1 if errors or outcomes != 42 else 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as error:
        print(f"Research runner failed: {type(error).__name__}: {error}", file=sys.stderr)
        sys.exit(2)
