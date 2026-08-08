<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Compilation\RecallCompilation;
use voku\AgentRecallCompiler\Compilation\RecallCompilationService;
use voku\AgentRecallCompiler\EvaluatedGuidance;
use voku\AgentRecallCompiler\ExclusionReason;
use voku\AgentRecallCompiler\Provider\LearningRecallProvider;
use voku\AgentRecallCompiler\Provider\MemoryRecallProvider;
use voku\AgentRecallCompiler\Provider\RecallProvider;
use voku\AgentRecallCompiler\Provider\ScopedDocumentRecallProvider;
use voku\AgentRecallCompiler\RecallRepository;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

final class CanonicalGuidanceHandoffTest extends TestCase
{
    private string $projectRoot;
    private string $learningRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/recall-handoff-' . bin2hex(random_bytes(8));
        $this->learningRoot = $this->projectRoot . '/infra/doc/agent-learning';
        mkdir($this->learningRoot . '/proposals/applied', 0777, true);
        mkdir($this->learningRoot . '/history', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testExactMemoryTargetUsesCanonicalFactInsteadOfAppliedProposal(): void
    {
        $memoryPath = $this->projectRoot . '/MEMORY.md';
        file_put_contents($memoryPath, "# Repository memory\n\nDurable handoff rule.\n");
        $this->writeAppliedProposal(
            'proposal.2026-08-08.001',
            'memory',
            'MEMORY.md',
            (string) hash_file('sha256', $memoryPath),
        );

        $compilation = $this->compile([
            new MemoryRecallProvider(new RecallRepository()),
            new LearningRecallProvider(new RecallRepository()),
        ]);

        self::assertSame([], array_map(static fn ($guidance): string => $guidance->id, $compilation->result->selectedGuidance));
        self::assertSame(
            ExclusionReason::CANONICAL_HOME_LOADED,
            $this->evaluated($compilation->result->evaluatedGuidance, 'proposal.2026-08-08.001')->exclusionReason,
        );
        $memoryFact = $this->fact($compilation->facts, 'memory.global');
        self::assertSame('MEMORY.md', $memoryFact['payload']['canonical_source_ref'] ?? null);
    }

    public function testMemoryHashDriftKeepsAppliedProposalSelected(): void
    {
        $memoryPath = $this->projectRoot . '/MEMORY.md';
        file_put_contents($memoryPath, "# Repository memory\n\nDurable handoff rule.\n");
        $this->writeAppliedProposal(
            'proposal.2026-08-08.002',
            'memory',
            'MEMORY.md',
            str_repeat('0', 64),
        );

        $compilation = $this->compile([
            new MemoryRecallProvider(new RecallRepository()),
            new LearningRecallProvider(new RecallRepository()),
        ]);

        self::assertSame(
            ['proposal.2026-08-08.002'],
            array_map(static fn ($guidance): string => $guidance->id, $compilation->result->selectedGuidance),
        );
        self::assertTrue($this->evaluated($compilation->result->evaluatedGuidance, 'proposal.2026-08-08.002')->selected);
    }

    public function testSkillFileExistingButNotLoadedDoesNotSuppressProposal(): void
    {
        $skillPath = $this->projectRoot . '/skills/auth-context.md';
        mkdir(dirname($skillPath), 0777, true);
        file_put_contents($skillPath, 'Use the verified auth-context procedure.');
        $manifest = $this->writeDocumentManifest('other/', '../skills/auth-context.md');
        $this->writeAppliedProposal(
            'proposal.2026-08-08.003',
            'skill',
            'skills/auth-context.md',
            (string) hash_file('sha256', $skillPath),
        );

        $compilation = $this->compile([
            new LearningRecallProvider(new RecallRepository()),
            new ScopedDocumentRecallProvider($manifest),
        ]);

        self::assertSame(
            ['proposal.2026-08-08.003'],
            array_map(static fn ($guidance): string => $guidance->id, $compilation->result->selectedGuidance),
        );
        self::assertSame([], array_values(array_filter(
            $compilation->facts,
            static fn (array $fact): bool => ($fact['type'] ?? null) === 'skill',
        )));
    }

    public function testSelectedSkillCanonicalSourceSuppressesAppliedProposal(): void
    {
        $skillPath = $this->projectRoot . '/skills/auth-context.md';
        mkdir(dirname($skillPath), 0777, true);
        file_put_contents($skillPath, 'Use the verified auth-context procedure.');
        $manifest = $this->writeDocumentManifest('src/', '../skills/auth-context.md');
        $this->writeAppliedProposal(
            'proposal.2026-08-08.004',
            'skill',
            'skills/auth-context.md',
            (string) hash_file('sha256', $skillPath),
        );

        $compilation = $this->compile([
            new LearningRecallProvider(new RecallRepository()),
            new ScopedDocumentRecallProvider($manifest),
        ]);

        self::assertSame([], $compilation->result->selectedGuidance);
        self::assertSame(
            ExclusionReason::CANONICAL_HOME_LOADED,
            $this->evaluated($compilation->result->evaluatedGuidance, 'proposal.2026-08-08.004')->exclusionReason,
        );
        $skillFact = $this->fact($compilation->facts, 'document.auth-context');
        self::assertSame('skills/auth-context.md', $skillFact['payload']['canonical_source_ref'] ?? null);
    }

    /** @param list<RecallProvider> $providers */
    private function compile(array $providers): RecallCompilation
    {
        return (new RecallCompilationService($providers))->compile(
            new TaskBrief('TASK-1', 'Exercise canonical handoff.', ['src/Auth.php']),
            new RecallRootConfig($this->learningRoot, 'constraints/active', $this->projectRoot),
        );
    }

    private function writeAppliedProposal(string $id, string $type, string $sourceRef, string $hash): void
    {
        file_put_contents(
            $this->learningRoot . '/proposals/applied/' . $id . '.json',
            json_encode([
                'schema_version' => '1.0',
                'id' => $id,
                'action' => 'ADD',
                'target_type' => $type,
                'target' => $type === 'memory' ? 'memory.project' : 'skill.auth-context',
                'scope' => ['src/'],
                'new' => $type === 'memory' ? 'Durable handoff rule.' : 'Use the verified auth-context procedure.',
                'reason' => 'Verified handoff fixture.',
                'boundary' => 'Use one active representation in a compile.',
                'validation' => ['Verify canonical target.'],
                'status' => 'applied',
                'applied_validation' => [
                    'target_source_ref' => $sourceRef,
                    'target_content_hash' => $hash,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private function writeDocumentManifest(string $scope, string $source): string
    {
        $directory = $this->projectRoot . '/docs';
        mkdir($directory, 0777, true);
        $manifest = $directory . '/recall-documents.json';
        file_put_contents($manifest, json_encode([
            'schema_version' => '1.0',
            'documents' => [[
                'id' => 'auth-context',
                'type' => 'skill',
                'source' => $source,
                'scope' => [$scope],
                'tags' => [],
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $manifest;
    }

    /** @param list<EvaluatedGuidance> $evaluated */
    private function evaluated(array $evaluated, string $id): EvaluatedGuidance
    {
        foreach ($evaluated as $item) {
            if ($item->guidanceId === $id) {
                return $item;
            }
        }

        self::fail('Missing evaluated guidance: ' . $id);
    }

    /**
     * @param list<array<string, mixed>> $facts
     * @return array<string, mixed>
     */
    private function fact(array $facts, string $id): array
    {
        foreach ($facts as $fact) {
            if (($fact['id'] ?? null) === $id) {
                return $fact;
            }
        }

        self::fail('Missing fact: ' . $id);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
