<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class ReviewPromptBuilder
{
    private const int MAX_BYTES = 5000;
    private const int MAX_SESSION_FILE_BYTES = 2097152;

    public function __construct(private readonly string $workspacePath) {}

    public function buildBlindSpotPrompt(ReviewReport $report, string $outputDir): string
    {
        $artifacts = $this->collectArtifacts($report->taskId, $outputDir);
        $lines = [
            '# L2 blind-spot analysis prompt for ' . $report->taskId,
            '',
            'You are reviewing recall-compiler workflow artifacts. Use only the artifacts below as evidence.',
            'Expose missing validation, missing outcome logging, mismatched selected guidance, unsafe assumptions, and handoff gaps. Do not approve code or durable learning.',
            '',
            '## Deterministic preflight findings',
            '',
        ];
        foreach ($report->findings ?: [new BlindSpotFinding('no_findings', ReviewSeverity::INFO, 'No deterministic findings were produced.', [])] as $finding) {
            $lines[] = '- [' . $finding->severity->value . '] ' . $finding->id . ': ' . $finding->message;
            foreach ($finding->evidence as $evidence) {
                $lines[] = '  - ' . $evidence;
            }
        }
        $lines = array_merge($lines, ['', '## Output contract', '', 'Return Markdown with headings: Summary, Critical blind spots, Evidence, Required next action, Close readiness.', 'Close readiness must be BLOCKED, NEEDS HUMAN REVIEW, or READY FOR HUMAN CLOSE.', '', '## Artifacts', '']);
        return $this->appendArtifacts($lines, $artifacts);
    }

    public function buildCodeReviewPrompt(string $taskId, string $outputDir): string
    {
        if (!BlindSpotReviewer::isValidTaskId($taskId)) {
            throw new RuntimeException('Invalid task id.');
        }
        $artifacts = $this->collectArtifacts($taskId, $outputDir);
        foreach ($this->taskFilesFromMeta($outputDir) as $file) {
            $this->addArtifact($artifacts, $file);
        }
        ksort($artifacts);
        $lines = [
            '# L2 code review prompt for ' . $taskId,
            '',
            'Review the complete implementation against the task/brief, recall briefing, validation plan, and real source artifacts below.',
            'Select one dominant installed `code-review-*` engineering lens. Do not run a default review swarm.',
            'Allow at most one HANDOFF, only with an installed target lens, observed `path:line`, and why that concern is dominant.',
            'If the primary lens or required evidence is unavailable, or a required HANDOFF target lens is unavailable, return `STATUS: blocked` and name the exact gap.',
            'Preserve the lens-local `STATUS: findings|clean|blocked` result. Do not treat it as workflow approval or claim commands were run.',
            '',
            '## Artifacts',
            '',
        ];
        return $this->appendArtifacts($lines, $artifacts);
    }

    /** @return array<string, string> */
    private function collectArtifacts(string $taskId, string $outputDir): array
    {
        $artifacts = [];
        foreach (['system.md', 'validation-plan.md', 'meta.json', 'recall-log.draft.json', 'feedback-assessment.draft.json'] as $name) {
            $this->addArtifact($artifacts, $this->relative($outputDir) . '/' . $name);
        }

        foreach ($this->taskIdSpecificArtifacts($taskId) as $relative) {
            $this->addArtifact($artifacts, $relative);
        }

        foreach ($this->relatedSessionFiles($taskId) as $relative) {
            $this->addArtifact($artifacts, $relative);
        }

        ksort($artifacts);
        return $artifacts;
    }

    /** @return list<string> */
    private function taskIdSpecificArtifacts(string $taskId): array
    {
        return [
            '.agent-loop/tasks/' . $taskId . '.md',
            '.agent-loop/todo/cards/' . $taskId . '.md',
            '.agent-loop/todo/jira/' . $taskId . '.md',
        ];
    }

    /** @return list<string> */
    private function relatedSessionFiles(string $taskId): array
    {
        $root = $this->path('.agent-loop/sessions');
        if (!is_dir($root)) {
            return [];
        }

        /** @var array<string, array{related: bool, files: list<string>}> $groups */
        $groups = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile() || !$item->isReadable() || $item->getSize() > self::MAX_SESSION_FILE_BYTES || !$this->looksTextFile($item)) {
                continue;
            }

            $path = $item->getPathname();
            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            $relative = $this->relativeToWorkspace($path);
            $groupKey = $this->sessionGroupKey($relative);
            $groups[$groupKey] ??= ['related' => false, 'files' => []];
            $groups[$groupKey]['files'][] = $relative;
            if ($this->containsTaskId($relative, $taskId) || $this->containsTaskId($content, $taskId)) {
                $groups[$groupKey]['related'] = true;
            }
        }

        $files = [];
        foreach ($groups as $group) {
            if ($group['related']) {
                array_push($files, ...$group['files']);
            }
        }
        sort($files);

        return $files;
    }

    private function containsTaskId(string $haystack, string $taskId): bool
    {
        return preg_match('/(?<![A-Za-z0-9._-])' . preg_quote($taskId, '/') . '(?![A-Za-z0-9._-])/i', $haystack) === 1;
    }

    private function looksTextFile(SplFileInfo $file): bool
    {
        return in_array(strtolower($file->getExtension()), ['md', 'txt', 'json', 'log', ''], true);
    }

    private function sessionGroupKey(string $relative): string
    {
        $prefix = '.agent-loop/sessions/';
        $withoutRoot = str_starts_with($relative, $prefix) ? substr($relative, strlen($prefix)) : $relative;
        $separator = strpos($withoutRoot, '/');

        return $separator === false ? $withoutRoot : substr($withoutRoot, 0, $separator);
    }

    private function relativeToWorkspace(string $path): string
    {
        $root = rtrim($this->workspacePath, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /** @return list<string> */
    private function taskFilesFromMeta(string $outputDir): array
    {
        $path = $this->path($this->relative($outputDir) . '/meta.json');
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['task_files']) || !is_array($decoded['task_files'])) {
            return [];
        }
        $files = [];
        foreach ($decoded['task_files'] as $file) {
            if (is_string($file) && $this->isSafeRelative($file)) {
                $files[] = $file;
            }
        }
        sort($files);
        return $files;
    }

    /** @param array<string, string> $artifacts */
    private function addArtifact(array &$artifacts, string $relative): void
    {
        if (!$this->isSafeRelative($relative)) {
            return;
        }
        $path = $this->path($relative);
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $content = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($content !== false) {
            $artifacts[$relative] = strlen($content) > self::MAX_BYTES ? rtrim(substr($content, 0, self::MAX_BYTES)) . "\n[truncated]" : rtrim($content);
        }
    }

    /**
     * @param list<string> $lines
     * @param array<string, string> $artifacts
     */
    private function appendArtifacts(array $lines, array $artifacts): string
    {
        if ($artifacts === []) {
            $lines[] = '_No artifacts found._';
        }
        foreach ($artifacts as $path => $content) {
            $fence = $this->fenceFor($content);
            array_push($lines, '### ' . $path, '', $fence . 'text', $content, $fence, '');
        }
        return rtrim(implode("\n", $lines)) . "\n";
    }

    private function fenceFor(string $content): string
    {
        preg_match_all('/`+/', $content, $matches);
        $max = 2;
        foreach ($matches[0] as $match) {
            $max = max($max, strlen($match));
        }

        return str_repeat('`', $max + 1);
    }

    private function isSafeRelative(string $relative): bool
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
            return false;
        }

        foreach (explode('/', trim($relative, '/')) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function relative(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '.';
        }

        if (str_starts_with($path, '/')) {
            $root = rtrim($this->workspacePath, '/') . '/';
            if (!str_starts_with($path, $root)) {
                throw new RuntimeException('Path escapes workspace root.');
            }
            $path = rtrim(substr($path, strlen($root)), '/');
        }

        if (!$this->isSafeRelative($path)) {
            throw new RuntimeException('Path traversal is not allowed.');
        }

        return rtrim($path, '/');
    }

    private function path(string $relative): string
    {
        if (!$this->isSafeRelative($relative)) {
            throw new RuntimeException('Path traversal is not allowed.');
        }

        return rtrim($this->workspacePath, '/') . '/' . ltrim($relative, '/');
    }
}
