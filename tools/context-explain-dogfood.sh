#!/usr/bin/env bash
set -euo pipefail

TASK_ID="ARC-17"
ACTOR="context-explain-dogfood"
AGENT_LOOP_BIN="${AGENT_LOOP_BIN:-build/agent-loop/bin/agent-loop}"
AGENT_MAP_BIN="${AGENT_MAP_BIN:-build/agent-loop/vendor/bin/agent-map}"
AGENT_RECALL_BIN="${AGENT_RECALL_BIN:-build/agent-loop/vendor/bin/agent-recall-compiler}"
PROMPT_MANIFEST="${PROMPT_MANIFEST:-build/agent-skills/skills/operational-prompting/operating-prompts.json}"
STATE_ROOT=".agent-loop"
LEARNING_ROOT="${STATE_ROOT}/learning"
RECALL_ROOT="${STATE_ROOT}/recall"
TASKS_ROOT="${STATE_ROOT}/tasks"
MAP_INDEX="${STATE_ROOT}/map/php-symbols.json"
REPORT_DIR="build/context-explain-dogfood"
TARGET_DIR="build/context-explain-targeted"

rm -rf "${STATE_ROOT}" "${REPORT_DIR}" "${TARGET_DIR}"
mkdir -p "${REPORT_DIR}"

php "${AGENT_LOOP_BIN}" init scaffold
mkdir -p "${TASKS_ROOT}" "${LEARNING_ROOT}"
printf '# %s\n\nGovern context-explain implementation through the real agent-loop workflow.\n' "${TASK_ID}" > "${TASKS_ROOT}/${TASK_ID}.md"
php "${AGENT_LOOP_BIN}" board card create "${TASK_ID}" \
  --title="Explain why and how recall context was selected" \
  --lane=READY \
  --status=Selected

cat > "${LEARNING_ROOT}/recall-documents.json" <<'JSON'
{
  "schema_version": "1.0",
  "documents": [
    {
      "id": "project.operating-prompts",
      "type": "adr",
      "source": "../../docs/operating-prompts.md",
      "scope": ["src/"],
      "tags": ["recall", "prompting"],
      "max_chars": 2400
    }
  ]
}
JSON

"${AGENT_MAP_BIN}" build \
  --root=. \
  --paths=src,tests \
  --out="${MAP_INDEX}" \
  --phpstan-config=phpstan.neon.dist

php "${AGENT_LOOP_BIN}" workflow plan "${TASK_ID}" \
  --by "${ACTOR}" \
  --file src/Compilation/RecallCompilationService.php \
  --file src/Provider/ProjectCapabilityRecallProvider.php \
  --goal "Make recall context selection explainable from deterministic repository evidence." \
  --non-goal "Do not create a universal evidence subsystem or LLM-generated rationale." \
  --validation "composer ci" \
  --tag recall \
  --tag prompting \
  --behavior-anchor "compiled recall facts -> context explain projection -> receiving agent decision" \
  --operating-prompt-manifest "${PROMPT_MANIFEST}" \
  --operating-prompt '{"id":"multi-pass-correctness-simplify","arguments":{}}'

php "${AGENT_LOOP_BIN}" workflow approve "${TASK_ID}" --by "${ACTOR}"
php "${AGENT_LOOP_BIN}" workflow context "${TASK_ID}" > "${REPORT_DIR}/workflow-context.txt"

selection_report="$(find "${RECALL_ROOT}" -type f -path "*/${TASK_ID}/selection-report.json" -print -quit)"
if [[ -z "${selection_report}" ]]; then
  echo "[FAIL] context explain dogfood: selection-report.json not found" >&2
  exit 1
fi
recall_dir="$(dirname "${selection_report}")"
cp "${selection_report}" "${REPORT_DIR}/selection-report.json"
cp "${recall_dir}/system.md" "${REPORT_DIR}/system.md"

php -r '
$path = $argv[1];
$systemPath = $argv[2];
$out = $argv[3];
$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$items = $data["context_explain"] ?? null;
if (!is_array($items)) {
    fwrite(STDERR, "[FAIL] context explain dogfood: context_explain is missing from selection-report.json\n");
    exit(42);
}
$find = static function (callable $predicate) use ($items): ?array {
    foreach ($items as $item) {
        if (is_array($item) && $predicate($item)) {
            return $item;
        }
    }
    return null;
};
$composerCi = $find(static fn(array $item): bool => ($item["what"] ?? null) === "composer ci");
$tool = $find(static fn(array $item): bool => ($item["kind"] ?? null) === "tool_presence");
$document = $find(static fn(array $item): bool => ($item["what"] ?? null) === "docs/operating-prompts.md");
$recipe = $find(static fn(array $item): bool => ($item["what"] ?? null) === "multi-pass-correctness-simplify (L2)");
$system = (string) file_get_contents($systemPath);
$checks = [
    "composer_ci_verified" => is_array($composerCi)
        && ($composerCi["state"] ?? null) === "verified"
        && ($composerCi["use"] ?? null) === "verification_candidate"
        && str_contains((string) ($composerCi["how"] ?? ""), "composer.json scripts.ci"),
    "tool_presence_is_not_command" => is_array($tool)
        && ($tool["use"] ?? null) === "capability_presence_only_do_not_infer_command"
        && str_contains((string) ($tool["how"] ?? ""), "does not prove"),
    "document_has_selection_reason" => is_array($document)
        && ($document["state"] ?? null) === "verified"
        && str_contains((string) ($document["why"] ?? ""), "scope overlap")
        && str_contains((string) ($document["why"] ?? ""), "tag overlap"),
    "l2_recipe_authority" => is_array($recipe)
        && ($recipe["authority"] ?? null) === "approved_session_brief"
        && ($recipe["use"] ?? null) === "construct_project_specific_l1_contract",
    "system_renders_provenance_not_rationale" => str_contains($system, "## Context Explain Plan")
        && str_contains($system, "not the implementing agent\x27s rationale"),
    "verified_state_is_provenance_not_content_truth" => str_contains($system, "VERIFIED` does not mean every statement inside the referenced source is automatically correct"),
];
$ok = !in_array(false, $checks, true);
file_put_contents($out, json_encode([
    "schema_version" => "1.0",
    "task_id" => "ARC-17",
    "selection_report" => $path,
    "checks" => $checks,
    "passed" => $ok,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
if (!$ok) {
    foreach ($checks as $name => $passed) {
        if (!$passed) {
            fwrite(STDERR, "[FAIL] context explain semantic check: {$name}\n");
        }
    }
    exit(43);
}
' "${selection_report}" "${recall_dir}/system.md" "${REPORT_DIR}/result.json"

# Exercise map-role explanations against current repository source, not only fixtures.
php "${AGENT_RECALL_BIN}" compile \
  --root "${LEARNING_ROOT}" \
  --task ARC-17-TARGET \
  --description "Explain the context selected for ContextExplainProjector::project." \
  --target 'voku\AgentRecallCompiler\Context\ContextExplainProjector::project' \
  --map-index "${MAP_INDEX}" \
  --map-root . \
  --output-dir "${TARGET_DIR}" \
  --compilation-id compilation.ARC-17-TARGET.dogfood

php -r '
$path = $argv[1];
$out = $argv[2];
$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$items = is_array($data["context_explain"] ?? null) ? $data["context_explain"] : [];
$primary = null;
$contextOnly = null;
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    if (($item["use"] ?? null) === "implementation_candidate" && ($item["state"] ?? null) === "verified") {
        $primary = $item;
    }
    if (($item["use"] ?? null) === "context_only_do_not_edit_from_selection_alone") {
        $contextOnly = $item;
    }
}
$checks = [
    "primary_verified" => is_array($primary),
    "primary_authority_is_repository_source" => is_array($primary)
        && ($primary["authority"] ?? null) === "repository_source_via_agent_map"
        && str_contains((string) ($primary["how"] ?? ""), "agent-map EditContextPlan role(s): primary"),
    "context_only_present" => is_array($contextOnly),
    "context_only_has_no_edit_permission" => is_array($contextOnly)
        && ($contextOnly["authority"] ?? null) === "repository_source_via_agent_map"
        && str_starts_with((string) ($contextOnly["use"] ?? ""), "context_only_"),
];
$ok = !in_array(false, $checks, true);
file_put_contents($out, json_encode([
    "schema_version" => "1.0",
    "checks" => $checks,
    "passed" => $ok,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
if (!$ok) {
    foreach ($checks as $name => $passed) {
        if (!$passed) {
            fwrite(STDERR, "[FAIL] targeted context explain semantic check: {$name}\n");
        }
    }
    exit(44);
}
' "${TARGET_DIR}/selection-report.json" "${REPORT_DIR}/targeted-result.json"

# Persist a concrete L1 built from the governed context before recording validation.
cat > "${REPORT_DIR}/l1.md" <<'MARKDOWN'
## Goal
Expose deterministic context provenance so a receiving agent can distinguish what was selected, why it matters, how Recall derived it, what authority it carries, how it may be used, and which evidence remains unknown or excluded.

## Context
The approved task is ARC-17. `selection-report.json` exposes project-native `composer ci` from `composer.json`, the scoped `docs/operating-prompts.md` ADR, and the selected `multi-pass-correctness-simplify` L2 recipe. Target-aware agent-map context is verified separately against `ContextExplainProjector::project`.

## Constraints
Do not invent commands from installed package names. Do not turn dependency or type-definition context into edit permission. Do not inject implementation rationale as context provenance. Keep UNKNOWN valid when evidence cannot support a stronger state.

## Verification
Run `composer ci`. Run the semantic dogfood checks against governed and target-aware Recall artifacts. Verify that detected tool presence does not become an invented project command. Generate and inspect the agent-loop blind-spot review.

## Done When
`composer ci` passes, semantic context-explain checks pass, target-aware Recall exposes a verified implementation candidate, dependencies remain context-only, tool presence does not become an invented command, and the review artifact is generated and inspected without weakening the approved contract.
MARKDOWN

php "${AGENT_LOOP_BIN}" workflow contract "${TASK_ID}" \
  --status ready \
  --from "${REPORT_DIR}/l1.md" \
  --by "${ACTOR}"

validation_started="$(date +%s%3N)"
composer ci | tee "${REPORT_DIR}/composer-ci.log"
validation_finished="$(date +%s%3N)"
validation_duration="$((validation_finished - validation_started))"
php "${AGENT_LOOP_BIN}" session validation record "${TASK_ID}" \
  --brief-revision 1 \
  --command "composer ci" \
  --status passed \
  --exit-code 0 \
  --duration-ms "${validation_duration}" \
  --by "${ACTOR}"

set +e
php "${AGENT_LOOP_BIN}" review blindspots "${TASK_ID}" > "${REPORT_DIR}/review-before-checkpoint.txt" 2>&1
review_exit="$?"
set -e
printf '%s\n' "${review_exit}" > "${REPORT_DIR}/review-before-checkpoint.exit"
if [[ "${review_exit}" -ne 0 ]]; then
  echo "[FAIL] context explain dogfood: review blindspots exited with ${review_exit}" >&2
  exit 45
fi
review_artifact="$(find . -type f -name "${TASK_ID}.blindspots.json" -print -quit 2>/dev/null || true)"
if [[ -z "${review_artifact}" ]]; then
  echo "[FAIL] context explain dogfood: blind-spot review artifact not found" >&2
  exit 45
fi
cp "${review_artifact}" "${REPORT_DIR}/blindspots.json"
php "${AGENT_LOOP_BIN}" session checkpoint "${TASK_ID}" \
  --title "Review" \
  --body "review blindspots ${TASK_ID} was generated and inspected by context-explain dogfood."

php "${AGENT_LOOP_BIN}" workflow status "${TASK_ID}" > "${REPORT_DIR}/workflow-status.txt"

echo "[OK] context explain dogfood: governed context, contract, validation, target-aware evidence, unsupported inference boundary, and review were exercised"
