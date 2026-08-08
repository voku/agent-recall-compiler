<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\OperatingPromptOutcomeDraftAugmenter;
use voku\AgentRecallCompiler\OperatingPromptRequest;
use voku\AgentRecallCompiler\OutcomeLogger;
use voku\AgentRecallCompiler\TaskBrief;

final class OperatingPromptOutcomeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-prompt-outcome-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDraftContainsOneOutcomeRowPerSelectedRecipe(): void
    {
        $task = new TaskBrief(
            'PROMPT-1',
            'Harden tests.',
            ['src/Parser.php'],
            operatingPrompts: [new OperatingPromptRequest('coverage-mutation', [
                'minimum_percentage_points' => 10,
                'mutation_command' => 'vendor/bin/infection',
            ])],
        );
        $draft = CanonicalJson::pretty([
            'schema_version' => '1.0',
            'compilation_id' => 'compilation.PROMPT-1.001',
            'task_id' => 'PROMPT-1',
            'task_files' => ['src/Parser.php'],
            'evaluated_guidance' => [],
            'guidance_outcomes' => [],
        ]);

        $augmented = json_decode(
            (new OperatingPromptOutcomeDraftAugmenter())->augment($draft, $task),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('coverage-mutation', $augmented['operating_prompt_outcomes'][0]['prompt_id']);
        self::assertTrue($augmented['operating_prompt_outcomes'][0]['selected']);
        self::assertFalse($augmented['operating_prompt_outcomes'][0]['applied']);
        self::assertSame('unknown', $augmented['operating_prompt_outcomes'][0]['outcome']);
        self::assertSame([], $augmented['operating_prompt_outcomes'][0]['evidence']);
        self::assertSame(
            CanonicalJson::digest(['minimum_percentage_points' => 10, 'mutation_command' => 'vendor/bin/infection']),
            $augmented['operating_prompt_outcomes'][0]['arguments_sha256'],
        );
    }

    public function testOutcomeLoggerPersistsRecipeEvidenceSeparatelyFromGuidance(): void
    {
        $draftPath = $this->root . '/draft.json';
        file_put_contents($draftPath, CanonicalJson::pretty([
            'schema_version' => '1.0',
            'compilation_id' => 'compilation.PROMPT-2.001',
            'task_id' => 'PROMPT-2',
            'task_files' => ['src/Parser.php'],
            'evaluated_guidance' => [],
            'guidance_outcomes' => [],
            'operating_prompt_outcomes' => [[
                'prompt_id' => 'regression-hunt',
                'arguments_sha256' => str_repeat('a', 64),
                'selected' => true,
                'applied' => true,
                'outcome' => 'helpful',
                'evidence' => ['new regression test exposed empty-string drift'],
                'comment' => 'Prevented a false green result.',
            ]],
        ]));

        self::assertSame(
            'compilation.PROMPT-2.001',
            (new OutcomeLogger())->log($this->root, $draftPath, 'lars', 'abc123'),
        );

        $lines = file($this->root . '/history/operating-prompt-outcomes.jsonl', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $event = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('regression-hunt', $event['prompt_id']);
        self::assertSame('helpful', $event['outcome']);
        self::assertSame(['new regression test exposed empty-string drift'], $event['evidence']);
        self::assertFileDoesNotExist($this->root . '/history/outcomes.jsonl');
    }

    public function testFinalRecipeAssessmentRequiresEvidence(): void
    {
        $draftPath = $this->root . '/draft.json';
        file_put_contents($draftPath, CanonicalJson::pretty([
            'schema_version' => '1.0',
            'compilation_id' => 'compilation.PROMPT-3.001',
            'task_id' => 'PROMPT-3',
            'task_files' => [],
            'evaluated_guidance' => [],
            'guidance_outcomes' => [],
            'operating_prompt_outcomes' => [[
                'prompt_id' => 'adversarial-review',
                'arguments_sha256' => str_repeat('b', 64),
                'selected' => true,
                'applied' => true,
                'outcome' => 'helpful',
                'evidence' => [],
                'comment' => null,
            ]],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires evidence for helpful');
        (new OutcomeLogger())->log($this->root, $draftPath, 'lars', 'abc123');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
