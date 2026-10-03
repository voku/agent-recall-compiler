<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\ExclusionReason;
use voku\AgentRecallCompiler\GuidanceType;
use voku\AgentRecallCompiler\Output\CompiledContextExplanationReader;
use voku\AgentRecallCompiler\Output\ContextExplainState;
use voku\AgentRecallCompiler\SelectionReason;

final class CompiledContextExplanationReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recall-context-explanation-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create context explanation fixture root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReadsPersistedSelectionReasonsConstraintsExplainItemsAndOutcomeStats(): void
    {
        $this->writeFixture();

        $explanation = (new CompiledContextExplanationReader())->read($this->root);

        self::assertNotNull($explanation);
        self::assertSame('compile.TEST-1', $explanation->compilationId);
        self::assertFalse($explanation->hasIntegrityFailures());
        self::assertCount(1, $explanation->constraints);
        self::assertSame('constraint.no-unsafe-write', $explanation->constraints[0]->id);
        self::assertFalse($explanation->constraints[0]->hasExtendedMetadata());
        self::assertNull($explanation->constraints[0]->scope);

        self::assertCount(2, $explanation->guidance);
        self::assertSame(GuidanceType::SKILL, $explanation->guidance[0]->guidanceType);
        self::assertTrue($explanation->guidance[0]->selected);
        self::assertSame(SelectionReason::SCOPE_OVERLAP, $explanation->guidance[0]->selectionReason);
        self::assertFalse($explanation->guidance[1]->selected);
        self::assertSame(ExclusionReason::NO_SCOPE_OVERLAP, $explanation->guidance[1]->exclusionReason);

        self::assertCount(1, $explanation->items);
        self::assertSame(ContextExplainState::UNKNOWN, $explanation->items[0]->state);
        self::assertFalse($explanation->items[0]->selected);
        self::assertSame('bounded context budget', $explanation->items[0]->whyNot);
        self::assertSame(3, $explanation->outcomeStats['guidance.selected']['selected_count']);
        self::assertSame(2, $explanation->outcomeStats['guidance.selected']['helpful_count']);
    }

    /**
     * An explain item can name the owner record it is about, as a typed field.
     *
     * A `learning_precedent` item is about one LearningNote. Until the item carried that id
     * as a field, a consumer relating it to the note had to strip a prefix off `id`, which is
     * Recall's own format. An item that is not about an owner record, and a report persisted
     * before the field existed, read back as "not recorded" - never as "no such record".
     */
    public function testReadsTheOwnerRecordIdAnItemIsAbout(): void
    {
        $this->writeFixture([$this->precedentItem(['subject_id' => 'learning-note.2026-10-03.abc123'])]);

        $explanation = (new CompiledContextExplanationReader())->read($this->root);

        self::assertNotNull($explanation);
        self::assertCount(2, $explanation->items);
        self::assertNull($explanation->items[0]->subjectId, 'A map omission is not about an owner record.');
        self::assertSame('learning-note.2026-10-03.abc123', $explanation->items[1]->subjectId);
    }

    public function testAReportWrittenBeforeTheFieldExistedStillReads(): void
    {
        $this->writeFixture([$this->precedentItem([])]);

        $explanation = (new CompiledContextExplanationReader())->read($this->root);

        self::assertNotNull($explanation);
        self::assertNull($explanation->items[1]->subjectId, 'Absent is not recorded, not "no note".');
    }

    public function testANonStringOwnerRecordIdIsRejectedLikeEveryOtherField(): void
    {
        $this->writeFixture([$this->precedentItem(['subject_id' => 42])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/context_explain\.subject_id/');
        (new CompiledContextExplanationReader())->read($this->root);
    }

    public function testReportsSelectionReportIntegrityDriftWithoutRecompiling(): void
    {
        $this->writeFixture();
        $path = $this->root . '/selection-report.json';
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        file_put_contents($path, $contents . "\n");

        $explanation = (new CompiledContextExplanationReader())->read($this->root);

        self::assertNotNull($explanation);
        self::assertTrue($explanation->hasIntegrityFailures());
        self::assertContains(
            'compiled Recall output file is stale: selection-report.json',
            $explanation->integrityFailures,
        );
    }

    public function testMissingSelectionReportIsExplicitForExistingCompiledOutput(): void
    {
        $this->writeFixture();
        unlink($this->root . '/selection-report.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('selection-report.json is missing');

        (new CompiledContextExplanationReader())->read($this->root);
    }

    /** @param list<array<string, mixed>> $extraItems */
    private function writeFixture(array $extraItems = []): void
    {
        $bundle = [
            'schema_version' => '1.0',
            'task' => ['id' => 'TEST-1', 'revision' => 2],
            'outcome_stats' => [
                'guidance.selected' => [
                    'selected_count' => 3,
                    'helpful_count' => 2,
                    'irrelevant_count' => 1,
                    'harmful_count' => 0,
                    'violation_detected_count' => 0,
                ],
            ],
        ];
        $bundleSha256 = CanonicalJson::digest($bundle);
        $selection = [
            'schema_version' => '1.0',
            'bundle_sha256' => $bundleSha256,
            'selected_constraints' => [[
                'id' => 'constraint.no-unsafe-write',
                'engine' => 'phpstan',
                'rule_identifier' => 'NoUnsafeWriteRule',
                'source_proposal' => 'proposal.1',
            ]],
            'evaluated_guidance' => [
                [
                    'guidance_id' => 'guidance.selected',
                    'guidance_type' => 'skill',
                    'eligible' => true,
                    'selected' => true,
                    'selection_reason' => 'scope_overlap',
                    'exclusion_reason' => null,
                    'task_files' => ['src/Foo.php'],
                    'source_proposal' => 'proposal.2',
                ],
                [
                    'guidance_id' => 'guidance.excluded',
                    'guidance_type' => 'memory',
                    'eligible' => false,
                    'selected' => false,
                    'selection_reason' => null,
                    'exclusion_reason' => 'no_scope_overlap',
                    'task_files' => ['src/Foo.php'],
                ],
            ],
            'warnings' => ['one persisted warning'],
            'context_explain' => [[
                'id' => 'map-omitted:1',
                'kind' => 'map_omission',
                'what' => 'symbol:Foo::bar',
                'why' => 'The candidate was considered while constructing bounded edit context.',
                'how' => 'agent-map EditContextPlan omission for role dependency.',
                'authority' => 'derived_navigation',
                'use' => 'investigate_if_relevant',
                'state' => 'unknown',
                'selected' => false,
                'source_ref' => 'map.json',
                'evidence_ids' => [],
                'why_not' => 'bounded context budget',
            ], ...$extraItems],
        ];

        $bundleJson = CanonicalJson::pretty($bundle);
        $selectionJson = CanonicalJson::pretty($selection);
        file_put_contents($this->root . '/recall.bundle.json', $bundleJson);
        file_put_contents($this->root . '/selection-report.json', $selectionJson);
        file_put_contents($this->root . '/meta.json', CanonicalJson::pretty([
            'schema_version' => '1.0',
            'task_id' => 'TEST-1',
            'compilation_id' => 'compile.TEST-1',
            'bundle_sha256' => $bundleSha256,
            'output_hashes' => [
                'recall.bundle.json' => hash('sha256', $bundleJson),
                'selection-report.json' => hash('sha256', $selectionJson),
            ],
        ]));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function precedentItem(array $extra): array
    {
        return [
            'id' => 'learning-precedent:learning-note.2026-10-03.abc123',
            'kind' => 'learning_precedent',
            'what' => 'Release semantic owners first',
            'why' => 'Deterministic LearningNote relevance: scope_match.',
            'how' => 'LearningNoteRecallProvider exact path-scope/tag selection.',
            'authority' => 'learning_precedent',
            'use' => 'historical_precedent_not_instruction',
            'state' => 'verified',
            'selected' => true,
            'source_ref' => 'agent-learning:learning-note.2026-10-03.abc123',
            'evidence_ids' => ['finding.2026-10-03.def456'],
        ] + $extra;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
