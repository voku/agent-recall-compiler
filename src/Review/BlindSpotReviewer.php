<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class BlindSpotReviewer
{
    /** @var list<string> */
    private const array VALIDATION_MARKERS = ['PHPStan', 'phpstan', 'PHPUnit', 'phpunit', 'composer ci', 'composer test', 'tests passed'];
    /** @var list<string> */
    private const array OUTCOME_MARKERS = ['log-outcome', 'recall-log.draft.json', 'outcome='];
    /** @var list<string> */
    private const array REVIEW_MARKERS = ['review blindspots', 'review-blindspots', 'L2 blind-spot'];
    /** @var list<string> */
    private const array TOKEN_NOISE_MARKERS = ['docker compose logs', 'grep -R', 'composer install', 'npm install'];
    /** @var list<string> */
    private const array SECURITY_MARKERS = ['auth', 'login', 'password', 'csrf', 'xss', 'sql', 'permission', 'role'];

    public function __construct(private readonly string $workspacePath) {}

    public static function isValidTaskId(string $taskId): bool
    {
        return $taskId !== ''
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $taskId) === 1
            && !str_contains($taskId, '..');
    }

    public function review(string $taskId, string $outputDir): ReviewReport
    {
        if (!self::isValidTaskId($taskId)) {
            throw new RuntimeException('Invalid task id.');
        }

        $findings = [];
        $meta = $this->relative($outputDir) . '/meta.json';
        if (!is_file($this->path($meta))) {
            $findings[] = new BlindSpotFinding('missing_recall_meta', ReviewSeverity::FAIL, 'Recall compiler metadata is missing for this task.', ['Expected file: ' . $meta]);
        }

        $validation = $this->relative($outputDir) . '/validation-plan.md';
        if (!is_file($this->path($validation))) {
            $findings[] = new BlindSpotFinding('missing_validation_plan', ReviewSeverity::FAIL, 'The compiled validation plan is missing.', ['Expected file: ' . $validation]);
        }

        $text = $this->collectReviewText($outputDir);
        if ($this->matchedMarkers($text, self::VALIDATION_MARKERS) === []) {
            $findings[] = new BlindSpotFinding('missing_validation_evidence', ReviewSeverity::WARN, 'No validation evidence marker was found near recall artifacts.', ['Searched markers: ' . implode(', ', self::VALIDATION_MARKERS)]);
        }
        if ($this->matchedMarkers($text, self::OUTCOME_MARKERS) === []) {
            $findings[] = new BlindSpotFinding('missing_outcome_closeout', ReviewSeverity::WARN, 'No evidence shows recall outcome close-out was prepared or logged.', ['Searched markers: ' . implode(', ', self::OUTCOME_MARKERS)]);
        }
        if ($this->matchedMarkers($text, self::REVIEW_MARKERS) === []) {
            $findings[] = new BlindSpotFinding('missing_review_checkpoint', ReviewSeverity::WARN, 'No blind-spot review checkpoint marker was found.', ['Searched markers: ' . implode(', ', self::REVIEW_MARKERS)]);
        }
        $noise = $this->matchedMarkers($text, self::TOKEN_NOISE_MARKERS);
        if ($noise !== []) {
            $findings[] = new BlindSpotFinding('token_noise_risk', ReviewSeverity::INFO, 'Recall artifacts mention commands that can create token noise.', ['Matched markers: ' . implode(', ', $noise)]);
        }

        $security = $this->matchedMarkers($text, self::SECURITY_MARKERS);
        if ($security !== []) {
            $findings[] = new BlindSpotFinding('security_sensitive_context', ReviewSeverity::WARN, 'Recall artifacts mention security-sensitive terms.', ['Matched markers: ' . implode(', ', $security)]);
        }

        return new ReviewReport($taskId, $findings);
    }

    private function collectReviewText(string $outputDir): string
    {
        $chunks = [];
        foreach (['system.md', 'validation-plan.md', 'meta.json', 'recall-log.draft.json', 'feedback-assessment.draft.json'] as $name) {
            $path = $this->path($this->relative($outputDir) . '/' . $name);
            if (is_file($path) && is_readable($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    $chunks[] = $content;
                }
            }
        }
        $sessionText = $this->collectRelatedSessionText($outputDir);
        if ($sessionText !== '') {
            $chunks[] = $sessionText;
        }

        return implode("\n", $chunks);
    }

    private function collectRelatedSessionText(string $outputDir): string
    {
        $taskId = $this->taskIdFromMeta($outputDir);
        $root = $this->path('session_plan');
        if ($taskId === null || !is_dir($root)) {
            return '';
        }

        $chunks = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile() || !$item->isReadable() || !$this->looksTextFile($item)) {
                continue;
            }

            $path = $item->getPathname();
            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            if (str_contains($this->relativeToWorkspace($path), $taskId) || str_contains($content, $taskId)) {
                $chunks[] = $this->stripTemplatePlaceholders($content);
            }
        }

        return implode("\n", $chunks);
    }

    private function taskIdFromMeta(string $outputDir): ?string
    {
        $path = $this->path($this->relative($outputDir) . '/meta.json');
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['task_id']) || !is_string($decoded['task_id'])) {
            return null;
        }

        return self::isValidTaskId($decoded['task_id']) ? $decoded['task_id'] : null;
    }

    private function stripTemplatePlaceholders(string $text): string
    {
        $kept = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/\A(?:[-*]\s*)?\*[^*]+\*\z/', trim($line)) === 1) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    private function looksTextFile(SplFileInfo $file): bool
    {
        return in_array(strtolower($file->getExtension()), ['md', 'txt', 'json', 'log', ''], true);
    }

    private function relativeToWorkspace(string $path): string
    {
        $root = rtrim($this->workspacePath, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * @param list<string> $markers
     *
     * @return list<string>
     */
    private function matchedMarkers(string $text, array $markers): array
    {
        $matches = [];
        $haystack = strtolower($text);
        foreach ($markers as $marker) {
            if (str_contains($haystack, strtolower($marker))) {
                $matches[] = $marker;
            }
        }
        return $matches;
    }

    private function relative(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '.';
        }
        if (str_starts_with($path, '/')) {
            $root = rtrim($this->workspacePath, '/') . '/';
            return str_starts_with($path, $root) ? rtrim(substr($path, strlen($root)), '/') : ltrim($path, '/');
        }
        return rtrim($path, '/');
    }

    private function path(string $relative): string
    {
        return rtrim($this->workspacePath, '/') . '/' . ltrim($relative, '/');
    }
}
