<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\RecallPromptBuilder;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\TaskBrief;
use voku\AgentRecallCompiler\TaskBriefParser;

final class TaskBriefContextTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-task-brief-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->root . '/work-brief.json')) {
            unlink($this->root . '/work-brief.json');
        }
        rmdir($this->root);
    }

    public function testParserKeepsOptionalBehaviorAnchorsFromAnApprovedWorkBrief(): void
    {
        $path = $this->root . '/work-brief.json';
        file_put_contents($path, json_encode([
            'schema_version' => '1.0',
            'task_id' => 'ABC-123',
            'goal' => 'Keep the actual request flow reviewable.',
            'scope' => ['src/Sync.php'],
            'non_goals' => [],
            'validation' => ['vendor/bin/phpunit'],
            'behavior_anchors' => ['POST request -> SyncAction -> directory gateway'],
            'targets' => ['App\\SyncAction::run', 'App\\SyncAction::run'],
            'status' => 'approved',
            'revision' => 1,
            'created_at' => '2026-08-02T10:00:00+00:00',
            'updated_at' => '2026-08-02T10:00:00+00:00',
        ], JSON_THROW_ON_ERROR));

        $brief = (new TaskBriefParser())->parseFile($path);

        self::assertSame(['POST request -> SyncAction -> directory gateway'], $brief->behaviorAnchors);
        self::assertSame(['App\\SyncAction::run'], $brief->targets);
    }

    public function testSystemPromptMakesBehaviorAnchorsAndEvidenceLabelsVisible(): void
    {
        $prompt = (new RecallPromptBuilder())->buildSystemMd(
            new TaskBrief(
                'ABC-123',
                'Keep the actual request flow reviewable.',
                ['src/Sync.php'],
                behaviorAnchors: ['POST request -> SyncAction -> directory gateway'],
            ),
            '',
            new RecallResult([], [], []),
        );

        self::assertStringContainsString('## Behavior Anchors', $prompt);
        self::assertStringContainsString('POST request -> SyncAction -> directory gateway', $prompt);
        self::assertStringContainsString('## Evidence Discipline', $prompt);
        self::assertStringContainsString('**VERIFIED**', $prompt);
        self::assertStringContainsString('**CONTRADICTED**', $prompt);
    }
}
