<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Context\ContextExplainProjector;
use voku\AgentRecallCompiler\EvaluatedGuidance;
use voku\AgentRecallCompiler\ExclusionReason;
use voku\AgentRecallCompiler\GuidanceType;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\Rendering\ContextExplainRenderer;
use voku\AgentRecallCompiler\SelectionReason;
use voku\AgentRecallCompiler\TaskBrief;

final class ContextExplainProjectorTest extends TestCase
{
    public function testMapContextExplainsEditPermissionUnknownsAndOmissions(): void
    {
        $items = (new ContextExplainProjector())->project(
            new TaskBrief('ARC-17', 'Explain context.', ['src/Service/UserService.php']),
            [[
                'id' => 'map.edit-context.user-service',
                'type' => 'edit_context',
                'authority' => 'project_metadata',
                'source_ref' => '.agent-map/php-symbols.json#App\\Service\\UserService::save',
                'payload' => [
                    'slices' => [
                        [
                            'path' => 'src/Service/UserService.php',
                            'line_start' => 10,
                            'line_end' => 20,
                            'roles' => ['primary'],
                            'reasons' => ['requested edit target'],
                            'evidence_ids' => ['symbol:save'],
                        ],
                        [
                            'path' => 'src/Repository/UserRepository.php',
                            'line_start' => 30,
                            'line_end' => 40,
                            'roles' => ['dependency'],
                            'reasons' => ['direct callee used by the target'],
                            'evidence_ids' => ['relation:callee'],
                        ],
                        [
                            'path' => 'src/Future/GeneratedContext.php',
                            'line_start' => 1,
                            'line_end' => 4,
                            'roles' => ['primary', 'future_role'],
                            'reasons' => ['role introduced by a newer map producer'],
                            'evidence_ids' => [],
                        ],
                    ],
                    'blind_spots' => [[
                        'kind' => 'dynamic_call',
                        'message' => 'Runtime target could not be resolved statically.',
                        'path' => 'src/Service/UserService.php',
                        'line' => 18,
                        'evidence_ids' => ['relation:dynamic'],
                    ]],
                    'omitted' => [[
                        'symbol_id' => 'method:App\\Import\\ImportUsers::run',
                        'role' => 'change_candidate',
                        'reason' => 'maximum caller count reached',
                    ]],
                ],
            ]],
            new RecallResult([], [], []),
        );
        $byWhat = $this->byWhat($items);

        self::assertSame('implementation_candidate', $byWhat['src/Service/UserService.php:10-20']['use']);
        self::assertSame('verified', $byWhat['src/Service/UserService.php:10-20']['state']);
        self::assertSame('repository_source_via_agent_map', $byWhat['src/Service/UserService.php:10-20']['authority']);
        self::assertSame(['symbol:save'], $byWhat['src/Service/UserService.php:10-20']['evidence_ids']);

        self::assertSame('context_only_do_not_edit_from_selection_alone', $byWhat['src/Repository/UserRepository.php:30-40']['use']);
        self::assertSame('verified', $byWhat['src/Repository/UserRepository.php:30-40']['state']);
        self::assertSame('repository_source_via_agent_map', $byWhat['src/Repository/UserRepository.php:30-40']['authority']);

        self::assertSame('context_only_until_verified', $byWhat['src/Future/GeneratedContext.php:1-4']['use']);
        self::assertSame('unknown', $byWhat['src/Future/GeneratedContext.php:1-4']['state']);
        self::assertSame('repository_source_via_agent_map', $byWhat['src/Future/GeneratedContext.php:1-4']['authority']);

        self::assertSame('investigate_before_claiming_complete', $byWhat['src/Service/UserService.php:18']['use']);
        self::assertSame('unknown', $byWhat['src/Service/UserService.php:18']['state']);
        self::assertSame('derived_navigation', $byWhat['src/Service/UserService.php:18']['authority']);

        self::assertFalse($byWhat['method:App\\Import\\ImportUsers::run']['selected']);
        self::assertSame('derived_navigation', $byWhat['method:App\\Import\\ImportUsers::run']['authority']);
        self::assertSame('maximum caller count reached', $byWhat['method:App\\Import\\ImportUsers::run']['why_not']);
    }

    public function testCapabilitiesDocumentsPromptsAndGuidanceKeepTheirEvidenceBoundaries(): void
    {
        $task = new TaskBrief(
            'ARC-17',
            'Explain context.',
            ['src/Compilation/RecallCompilationService.php'],
            tags: ['recall', 'prompting'],
        );
        $result = new RecallResult(
            [],
            [],
            [],
            evaluatedGuidance: [
                new EvaluatedGuidance(
                    'guidance.selected',
                    GuidanceType::SKILL,
                    true,
                    true,
                    SelectionReason::SCOPE_OVERLAP,
                    null,
                    ['src/Compilation/RecallCompilationService.php'],
                    'proposal.selected',
                ),
                new EvaluatedGuidance(
                    'guidance.excluded',
                    GuidanceType::SKILL,
                    false,
                    false,
                    null,
                    ExclusionReason::NO_SCOPE_OVERLAP,
                    ['src/Compilation/RecallCompilationService.php'],
                    'proposal.excluded',
                ),
            ],
        );
        $facts = [
            [
                'id' => 'project.capabilities',
                'type' => 'project_capabilities',
                'authority' => 'project_metadata',
                'source_ref' => '/repo/composer.json',
                'payload' => [
                    'runtime_constraint' => '^8.3',
                    'composer_scripts' => [
                        'test' => 'phpunit',
                        'ci' => ['@test', '@phpstan'],
                    ],
                    'tool_packages' => ['phpunit/phpunit' => '^11.5'],
                    'config_files' => ['phpstan.neon.dist'],
                    'ci_workflows' => ['.github/workflows/ci.yml'],
                ],
            ],
            [
                'id' => 'document.project.operating-prompts',
                'type' => 'adr',
                'authority' => 'project_adr',
                'source_ref' => '../../../docs/operating-prompts.md',
                'scope' => ['src/'],
                'payload' => [
                    'document_id' => 'project.operating-prompts',
                    'tags' => ['recall', 'prompting'],
                    'canonical_source_ref' => 'docs/operating-prompts.md',
                ],
            ],
            [
                'id' => 'operating-prompt.multi-pass-correctness-simplify',
                'type' => 'operating_prompt',
                'authority' => 'approved_session_brief',
                'source_ref' => 'operating-prompts.json#multi-pass-correctness-simplify',
                'payload' => [
                    'prompt_id' => 'multi-pass-correctness-simplify',
                    'level' => 2,
                    'template_sha256' => str_repeat('a', 64),
                ],
            ],
        ];

        $items = (new ContextExplainProjector())->project($task, $facts, $result);
        $byWhat = $this->byWhat($items);

        self::assertSame('verification_candidate', $byWhat['composer ci']['use']);
        self::assertSame('verified', $byWhat['composer ci']['state']);
        self::assertStringContainsString('composer.json scripts.ci', $byWhat['composer ci']['how']);

        self::assertSame('capability_presence_only_do_not_infer_command', $byWhat['phpunit/phpunit ^11.5']['use']);
        self::assertStringContainsString('does not prove', $byWhat['phpunit/phpunit ^11.5']['how']);

        self::assertSame('verified', $byWhat['docs/operating-prompts.md']['state']);
        self::assertStringContainsString('scope overlap', $byWhat['docs/operating-prompts.md']['why']);
        self::assertStringContainsString('tag overlap', $byWhat['docs/operating-prompts.md']['why']);
        self::assertSame('architecture_constraint', $byWhat['docs/operating-prompts.md']['use']);

        self::assertSame('construct_project_specific_l1_contract', $byWhat['multi-pass-correctness-simplify (L2)']['use']);
        self::assertSame('approved_session_brief', $byWhat['multi-pass-correctness-simplify (L2)']['authority']);

        self::assertTrue($byWhat['guidance.selected']['selected']);
        self::assertStringContainsString('scope_overlap', $byWhat['guidance.selected']['why']);
        self::assertFalse($byWhat['guidance.excluded']['selected']);
        self::assertSame('no_scope_overlap', $byWhat['guidance.excluded']['why_not']);
    }

    public function testProjectionAndRenderingAreDeterministicAndDescribeProvenanceNotImplementationRationale(): void
    {
        $task = new TaskBrief('ARC-17', 'Explain context.', ['src/Foo.php']);
        $facts = [[
            'id' => 'project.capabilities',
            'type' => 'project_capabilities',
            'authority' => 'project_metadata',
            'source_ref' => '/repo/composer.json',
            'payload' => [
                'composer_scripts' => ['ci' => ['@test', '@phpstan'], 'test' => 'phpunit'],
                'tool_packages' => [],
                'config_files' => [],
                'ci_workflows' => [],
            ],
        ]];
        $projector = new ContextExplainProjector();
        $first = $projector->project($task, $facts, new RecallResult([], [], []));
        $second = $projector->project($task, $facts, new RecallResult([], [], []));

        self::assertSame($first, $second);
        $markdown = (new ContextExplainRenderer())->render($first);
        self::assertStringContainsString('## Context Explain Plan', $markdown);
        self::assertStringContainsString('context provenance', $markdown);
        self::assertStringContainsString('not the implementing agent\'s rationale', $markdown);
        self::assertStringContainsString('VERIFIED` does not mean every statement inside the referenced source is automatically correct', $markdown);
        self::assertStringContainsString('### composer ci', $markdown);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, array<string, mixed>>
     */
    private function byWhat(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $what = $item['what'] ?? null;
            if (is_string($what)) {
                $indexed[$what] = $item;
            }
        }

        return $indexed;
    }
}
