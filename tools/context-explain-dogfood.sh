#!/usr/bin/env bash
set -euo pipefail

TASK_ID="ARC-17"
ACTOR="context-explain-dogfood"
AGENT_LOOP_BIN="${AGENT_LOOP_BIN:-build/agent-loop/bin/agent-loop}"
AGENT_MAP_BIN="${AGENT_MAP_BIN:-build/agent-loop/vendor/bin/agent-map}"
PROMPT_MANIFEST="${PROMPT_MANIFEST:-build/agent-skills/skills/operational-prompting/operating-prompts.json}"
LEARNING_ROOT="infra/doc/agent-learning"
REPORT_DIR="build/context-explain-dogfood"

rm -rf session_plan .agent-loop .agent-map todo tasks "${LEARNING_ROOT}" "${REPORT_DIR}"
mkdir -p "${REPORT_DIR}"

php "${AGENT_LOOP_BIN}" init scaffold
mkdir -p tasks
printf '# %s\n\nGovern context-explain implementation through the real agent-loop workflow.\n' "${TASK_ID}" > "tasks/${TASK_ID}.md"
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
      "source": "../../../docs/operating-prompts.md",
      "scope": ["src/"],
      "tags": ["recall", "prompting"],
      "max_chars": 2400
    }
  ]
}
JSON

mkdir -p .agent-map
"${AGENT_MAP_BIN}" build \
  --root=. \
  --paths=src,tests \
  --out=.agent-map/php-symbols.json \
  --phpstan-config=phpstan.neon.dist

php "${AGENT_LOOP_BIN}" workflow plan "${TASK_ID}" \
  --by "${ACTOR}" \
  --file src/Compilation/RecallCompilationService.php \
  --file src/Provider/ProjectCapabilityRecallProvider.php \
  --scope src/ \
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

selection_report="$(find "${LEARNING_ROOT}" -type f -path "*/${TASK_ID}/selection-report.json" -print -quit)"
if [[ -z "${selection_report}" ]]; then
  echo "[FAIL] context explain dogfood: selection-report.json not found" >&2
  exit 1
fi

cp "${selection_report}" "${REPORT_DIR}/selection-report.json"

php -r '
$path = $argv[1];
$out = $argv[2];
$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$present = isset($data["context_explain"]) && is_array($data["context_explain"]);
file_put_contents($out, json_encode([
    "schema_version" => "1.0",
    "task_id" => "ARC-17",
    "selection_report" => $path,
    "context_explain_present" => $present,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
if (!$present) {
    fwrite(STDERR, "[FAIL] context explain dogfood: context_explain is missing from selection-report.json\n");
    exit(42);
}
' "${selection_report}" "${REPORT_DIR}/result.json"

echo "[OK] context explain dogfood: governed compile exposes context_explain"
