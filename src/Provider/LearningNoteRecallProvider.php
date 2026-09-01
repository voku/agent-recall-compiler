<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\RecallCompilationBlockedException;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

final readonly class LearningNoteRecallProvider implements RecallProvider
{
    private const int MAX_RENDERED_PRECEDENTS = 5;
    private const int MAX_CONTENT_CHARS = 1800;

    public function __construct(
        private LearningNoteProjectionSource $source = new AgentLearningNoteProjectionSource(),
    ) {
    }

    public function manifest(): RecallProviderManifest
    {
        return new RecallProviderManifest('agent-learning-notes', '1.0', [], required: false);
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        $eligible = [];
        foreach ($this->source->active($rootConfig->root) as $note) {
            if ($note->evidenceState === 'source_missing') {
                throw new RecallCompilationBlockedException(
                    'Compilation blocked: LearningNote ' . $note->id . ' references missing repository evidence.',
                );
            }
            if (!in_array($note->evidenceState, ['current', 'review_needed', 'no_hashable_repository_evidence'], true)) {
                throw new RecallCompilationBlockedException(
                    'Compilation blocked: LearningNote ' . $note->id . ' has unsupported owner evidence state ' . $note->evidenceState . '.',
                );
            }

            $match = $this->match($note, $task);
            if ($match === null) {
                continue;
            }
            $eligible[] = ['note' => $note, 'match' => $match];
        }

        usort($eligible, function (array $left, array $right): int {
            /** @var LearningNotePrecedentProjection $leftNote */
            $leftNote = $left['note'];
            /** @var LearningNotePrecedentProjection $rightNote */
            $rightNote = $right['note'];
            /** @var array{files: list<string>, tags: list<string>, specificity: int, reasons: list<string>} $leftMatch */
            $leftMatch = $left['match'];
            /** @var array{files: list<string>, tags: list<string>, specificity: int, reasons: list<string>} $rightMatch */
            $rightMatch = $right['match'];

            $state = $this->stateRank($rightNote->evidenceState) <=> $this->stateRank($leftNote->evidenceState);
            if ($state !== 0) {
                return $state;
            }
            $specificity = $rightMatch['specificity'] <=> $leftMatch['specificity'];
            if ($specificity !== 0) {
                return $specificity;
            }
            $tagOverlap = count($rightMatch['tags']) <=> count($leftMatch['tags']);
            if ($tagOverlap !== 0) {
                return $tagOverlap;
            }

            return $leftNote->id <=> $rightNote->id;
        });

        $facts = [];
        $renderedCurrent = 0;
        foreach ($eligible as $candidate) {
            /** @var LearningNotePrecedentProjection $note */
            $note = $candidate['note'];
            /** @var array{files: list<string>, tags: list<string>, specificity: int, reasons: list<string>} $match */
            $match = $candidate['match'];
            $render = $note->evidenceState === 'current' && $renderedCurrent < self::MAX_RENDERED_PRECEDENTS;
            $omissionReason = null;
            if ($note->evidenceState === 'review_needed') {
                $omissionReason = 'review_needed';
            } elseif ($note->evidenceState === 'no_hashable_repository_evidence') {
                $omissionReason = 'no_hashable_repository_evidence';
            } elseif (!$render) {
                $omissionReason = 'context_budget';
            }
            if ($render) {
                ++$renderedCurrent;
            }

            $facts[] = new RecallFact(
                id: 'learning-precedent.' . $note->id,
                type: 'learning_precedent',
                authority: 'learning_precedent',
                sourceRef: 'agent-learning:' . $note->id,
                scope: $note->scope,
                payload: [
                    'note_id' => $note->id,
                    'pattern_key' => $note->patternKey,
                    'title' => $this->contentString($note->content, 'title'),
                    'content' => $render ? $this->boundedContent($note->content) : [],
                    'source_findings' => $note->sourceFindings,
                    'source_proposals' => $note->sourceProposals,
                    'note_digest' => $note->digest,
                    'evidence_state' => $note->evidenceState,
                    'matching_task_files' => $match['files'],
                    'matching_tags' => $match['tags'],
                    'match_reasons' => $match['reasons'],
                    'scope_specificity' => $match['specificity'],
                    'render' => $render,
                    'omission_reason' => $omissionReason,
                ],
                lifecycle: $note->evidenceState === 'current' ? 'active' : 'historical',
            );
        }

        return new RecallProviderResult(
            sourceDigest: CanonicalJson::digest([
                'provider' => $this->manifest()->id,
                'eligible' => array_map(static fn (RecallFact $fact): array => $fact->toArray(), $facts),
            ]),
            facts: $facts,
        );
    }

    /**
     * @return array{files: list<string>, tags: list<string>, specificity: int, reasons: list<string>}|null
     */
    private function match(LearningNotePrecedentProjection $note, TaskBrief $task): ?array
    {
        $scope = array_values(array_unique(array_map($this->normalizePath(...), $note->scope)));
        $global = $scope === [] || in_array('*', $scope, true) || in_array('/', $scope, true);
        $matchingFiles = [];
        $specificity = 0;
        if ($global) {
            $specificity = 1;
        } else {
            foreach ($task->files as $taskFile) {
                $file = $this->normalizePath($taskFile);
                foreach ($scope as $candidate) {
                    $prefix = rtrim($candidate, '/');
                    if ($prefix === '') {
                        continue;
                    }
                    if ($file === $prefix || str_starts_with($file, $prefix . '/')) {
                        $matchingFiles[] = $file;
                        $specificity = max($specificity, strlen($prefix) + ($file === $prefix ? 10000 : 0));
                    }
                }
            }
        }
        $matchingFiles = array_values(array_unique($matchingFiles));
        sort($matchingFiles, SORT_STRING);

        $noteTags = $this->canonicalTags($note->tags);
        $taskTags = $this->canonicalTags($task->tags);
        $matchingTags = array_values(array_intersect($noteTags, $taskTags));
        sort($matchingTags, SORT_STRING);

        if (!$global && $matchingFiles === [] && $matchingTags === []) {
            return null;
        }

        $reasons = [];
        if ($global) {
            $reasons[] = 'project_wide_scope';
        }
        if ($matchingFiles !== []) {
            $reasons[] = 'scope_match';
        }
        if ($matchingTags !== []) {
            $reasons[] = 'tag_match';
        }

        return [
            'files' => $matchingFiles,
            'tags' => $matchingTags,
            'specificity' => $specificity,
            'reasons' => $reasons,
        ];
    }

    /** @return array<string, mixed> */
    private function boundedContent(array $content): array
    {
        $result = [];
        foreach ([
            'context',
            'guidance',
            'why_it_works',
            'when_to_apply',
            'when_not_to_apply',
            'verification',
            'symptoms',
            'root_cause',
        ] as $key) {
            $value = $content[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $result[$key] = $this->truncate(trim($value), self::MAX_CONTENT_CHARS);
        }
        foreach (['failed_approaches', 'examples'] as $key) {
            $value = $content[$key] ?? null;
            if (!is_array($value)) {
                continue;
            }
            $items = [];
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $items[] = $this->truncate(trim($item), 500);
                }
                if (count($items) >= 3) {
                    break;
                }
            }
            if ($items !== []) {
                $result[$key] = $items;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $content */
    private function contentString(array $content, string $key): string
    {
        $value = $content[$key] ?? null;
        return is_string($value) ? trim($value) : '';
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_strcut($value, 0, $limit, 'UTF-8')) . ' [truncated by LearningNote precedent provider]';
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '/' || $path === '*') {
            return $path;
        }

        return ltrim(preg_replace('~/+~', '/', $path) ?? $path, './');
    }

    /** @param list<string> $tags @return list<string> */
    private function canonicalTags(array $tags): array
    {
        $result = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim($tag));
            if ($tag !== '') {
                $result[] = $tag;
            }
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);

        return $result;
    }

    private function stateRank(string $state): int
    {
        return match ($state) {
            'current' => 3,
            'review_needed' => 2,
            'no_hashable_repository_evidence' => 1,
            default => 0,
        };
    }
}
