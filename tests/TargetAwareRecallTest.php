<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Compilation\TaskScopeResolver;
use voku\AgentRecallCompiler\RecallPromptBuilder;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\Rendering\EditContextRenderer;
use voku\AgentRecallCompiler\TaskBrief;

final class TargetAwareRecallTest extends TestCase
{
    public function testEffectiveScopeIncludesOnlyLikelyEditAndVerificationFiles(): void
    {
        $task = new TaskBrief(
            id: 'TASK-1',
            description: 'Change UserService::save',
            files: ['docs/request.md'],
            targets: ['App\\Service\\UserService::save'],
        );
        $facts = [[
            'id' => 'map.edit-context.user-service',
            'type' => 'edit_context',
            'payload' => [
                'slices' => [
                    ['path' => 'src/Service/UserService.php', 'roles' => ['primary']],
                    ['path' => 'src/Contract/UserServiceInterface.php', 'roles' => ['contract']],
                    ['path' => 'src/Controller/UserController.php', 'roles' => ['change_candidate']],
                    ['path' => 'tests/Service/UserServiceTest.php', 'roles' => ['verification']],
                    ['path' => 'src/Repository/UserRepository.php', 'roles' => ['dependency']],
                    ['path' => 'src/Entity/User.php', 'roles' => ['type_definition']],
                ],
            ],
        ]];

        $resolution = (new TaskScopeResolver())->resolve($task, $facts);

        self::assertSame([
            'docs/request.md',
            'src/Contract/UserServiceInterface.php',
            'src/Controller/UserController.php',
            'src/Service/UserService.php',
            'tests/Service/UserServiceTest.php',
        ], $resolution->effectiveTask->files);
        self::assertSame([
            'src/Contract/UserServiceInterface.php',
            'src/Controller/UserController.php',
            'src/Service/UserService.php',
            'tests/Service/UserServiceTest.php',
        ], $resolution->derivedFiles);
        self::assertSame(['map.edit-context.user-service'], $resolution->derivedFrom);
        self::assertNotContains('src/Repository/UserRepository.php', $resolution->effectiveTask->files);
        self::assertNotContains('src/Entity/User.php', $resolution->effectiveTask->files);
    }

    public function testEditContextRendererShowsRolesSourceBlindSpotsAndOmissions(): void
    {
        $facts = [$this->editContextFact()];

        $rendered = (new EditContextRenderer())->render($facts);

        self::assertStringContainsString('## Repository Edit Context', $rendered);
        self::assertStringContainsString('### Target: `App\\Service\\UserService::save`', $rendered);
        self::assertStringContainsString('#### Change Candidates', $rendered);
        self::assertStringContainsString('`src/Controller/UserController.php:20-25`', $rendered);
        self::assertStringContainsString('direct caller that may need adaptation', $rendered);
        self::assertStringContainsString('#### Dependencies', $rendered);
        self::assertStringContainsString('Context only. Do not edit merely because it was selected.', $rendered);
        self::assertStringContainsString('#### Static-analysis Blind Spots', $rendered);
        self::assertStringContainsString('runtime target could not be resolved', $rendered);
        self::assertStringContainsString('#### Omitted Context', $rendered);
        self::assertStringContainsString('maximum caller count reached', $rendered);
        self::assertStringNotContainsString('relation:secret-evidence', $rendered);
    }

    public function testEditContextRendererSurfacesUnknownRolesAsOtherContext(): void
    {
        $fact = $this->editContextFact();
        $fact['payload']['slices'][] = [
            'path' => 'src/Future/GeneratedContext.php',
            'line_start' => 1,
            'line_end' => 4,
            'roles' => ['future_role'],
            'reasons' => ['role introduced by a newer map producer'],
            'evidence_ids' => [],
            'source_sha256' => 'sha256:future',
            'content' => "final class GeneratedContext\n{\n}\n",
        ];

        $rendered = (new EditContextRenderer())->render([$fact]);

        self::assertStringContainsString('#### Other Context', $rendered);
        self::assertStringContainsString('Unrecognized map role. Treat as context until verified.', $rendered);
        self::assertStringContainsString('`src/Future/GeneratedContext.php:1-4`', $rendered);
    }

    public function testPromptBuilderEmbedsRenderedEditContext(): void
    {
        $prompt = (new RecallPromptBuilder())->buildSystemMd(
            new TaskBrief('TASK-1', 'Change save behavior.', [], targets: ['App\\Service\\UserService::save']),
            '',
            new RecallResult([], [], []),
            facts: [$this->editContextFact()],
        );

        self::assertStringContainsString('## Repository Edit Context', $prompt);
        self::assertStringContainsString('public function save(): void', $prompt);
    }

    /** @return array<string, mixed> */
    private function editContextFact(): array
    {
        return [
            'id' => 'map.edit-context.user-service',
            'type' => 'edit_context',
            'source_ref' => '.agent-map/map.json#App\\Service\\UserService::save',
            'payload' => [
                'target' => [
                    'requested' => 'App\\Service\\UserService::save',
                    'resolved' => 'App\\Service\\UserService::save',
                    'file' => 'src/Service/UserService.php',
                    'line_start' => 10,
                    'line_end' => 15,
                ],
                'map_digest' => 'sha256:map',
                'source_bytes' => 128,
                'slices' => [
                    [
                        'path' => 'src/Service/UserService.php',
                        'line_start' => 10,
                        'line_end' => 15,
                        'roles' => ['primary'],
                        'reasons' => ['requested edit target'],
                        'evidence_ids' => ['relation:secret-evidence'],
                        'source_sha256' => 'sha256:source',
                        'content' => "public function save(): void\n{\n}\n",
                    ],
                    [
                        'path' => 'src/Controller/UserController.php',
                        'line_start' => 20,
                        'line_end' => 25,
                        'roles' => ['change_candidate'],
                        'reasons' => ['direct caller that may need adaptation'],
                        'evidence_ids' => ['relation:caller'],
                        'source_sha256' => 'sha256:caller',
                        'content' => "public function submit(): void\n{\n}\n",
                    ],
                    [
                        'path' => 'src/Repository/UserRepository.php',
                        'line_start' => 30,
                        'line_end' => 35,
                        'roles' => ['dependency'],
                        'reasons' => ['direct callee used by the target'],
                        'evidence_ids' => ['relation:callee'],
                        'source_sha256' => 'sha256:callee',
                        'content' => "public function persist(): void\n{\n}\n",
                    ],
                ],
                'blind_spots' => [[
                    'kind' => 'dynamic_call',
                    'message' => 'The runtime target could not be resolved statically.',
                    'path' => 'src/Service/UserService.php',
                    'line' => 14,
                    'evidence_ids' => ['relation:dynamic'],
                ]],
                'omitted' => [[
                    'symbol_id' => 'method:App\\Import\\ImportUsers::run',
                    'role' => 'change_candidate',
                    'reason' => 'maximum caller count reached',
                ]],
            ],
        ];
    }
}
