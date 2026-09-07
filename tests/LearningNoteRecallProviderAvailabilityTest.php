<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Provider\LearningNoteProjectionSource;
use voku\AgentRecallCompiler\Provider\LearningNoteRecallProvider;
use voku\AgentRecallCompiler\Provider\LearningTaskPrecedentProjection;
use voku\AgentRecallCompiler\RecallRootConfig;

final class LearningNoteRecallProviderAvailabilityTest extends TestCase
{
    public function testInstalledLearningRemainsOptionalWhenProjectLearningRootIsAbsent(): void
    {
        $source = new class implements LearningNoteProjectionSource {
            public function isAvailable(): bool
            {
                return true;
            }

            public function forTask(
                string $learningRoot,
                string $taskId,
                ?string $projectRoot = null,
            ): LearningTaskPrecedentProjection {
                throw new LogicException('Owner projection must not be queried for an absent Learning root.');
            }
        };

        $learningRoot = sys_get_temp_dir() . '/agent-recall-missing-learning-' . bin2hex(random_bytes(8));
        self::assertDirectoryDoesNotExist($learningRoot);

        $provider = new LearningNoteRecallProvider($source);

        self::assertFalse(
            $provider->isAvailable(new RecallRootConfig($learningRoot, 'constraints/active')),
        );
    }
}
