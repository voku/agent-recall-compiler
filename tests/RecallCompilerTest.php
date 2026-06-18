<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\TaskBrief;
use voku\AgentRecallCompiler\TaskBriefParser;
use voku\AgentRecallCompiler\RecallRepository;
use voku\AgentRecallCompiler\RecallDecisionEngine;
use voku\AgentRecallCompiler\RecallPromptBuilder;
use voku\AgentRecallCompiler\OutcomeLogger;
use voku\AgentRecallCompiler\RecallGuidance;
use voku\AgentRecallCompiler\RecallRejection;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\ConstraintManifest;

final class RecallCompilerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recall-compiler-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/proposals/approved', 0777, true);
        mkdir($this->root . '/proposals/applied', 0777, true);
        mkdir($this->root . '/proposals/rejected', 0777, true);
        mkdir($this->root . '/constraints/active', 0777, true);
        mkdir($this->root . '/history', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testParsesTaskBriefSuccessfully(): void
    {
        $briefPath = $this->root . '/task-brief.json';
        file_put_contents($briefPath, json_encode([
            'id' => 'ITPNG-123',
            'description' => 'Test task brief description',
            'files' => ['src/Auth.php', 'tests/AuthTest.php']
        ]));

        $parser = new TaskBriefParser();
        $brief = $parser->parseFile($briefPath);

        self::assertSame('ITPNG-123', $brief->id);
        self::assertSame('Test task brief description', $brief->description);
        self::assertSame(['src/Auth.php', 'tests/AuthTest.php'], $brief->files);
    }

    public function testDecidesGuidanceMatchingScope(): void
    {
        $activeGuidance = [
            new RecallGuidance('g-1', 'ADD', 'skill', 'auth', ['src/Auth'], null, 'Wording 1', 'Reason 1', 'Boundary 1', ['make test'], 'approved'),
            new RecallGuidance('g-2', 'REPLACE', 'skill', 'db', ['src/Database'], 'Old', 'New', 'Reason 2', 'Boundary 2', [], 'applied'),
            new RecallGuidance('g-global', 'ADD', 'skill', 'any', ['/'], null, 'Wording global', 'Reason global', null, [], 'approved')
        ];

        $rejectedGuidance = [
            new RecallRejection('r-1', 'Contradictory', ['src/Auth'], 'ADD', 'auth-different-target')
        ];

        $outcomes = [
            [
                'task_id' => 'ITPNG-100',
                'guidance_used' => ['g-1'],
                'harmful' => ['g-1'],
                'comment' => 'Caused side effects'
            ]
        ];

        $engine = new RecallDecisionEngine();
        $task = new TaskBrief('ITPNG-123', 'Implement auth logic', ['src/Auth/OAuth.php']);

        $result = $engine->decide($task, $activeGuidance, $rejectedGuidance, $outcomes);

        // Matches g-1 (scope src/Auth covers src/Auth/OAuth.php) and g-global (scope / is global)
        self::assertCount(2, $result->selectedGuidance);
        self::assertSame('g-1', $result->selectedGuidance[0]->id);
        self::assertSame('g-global', $result->selectedGuidance[1]->id);

        // Matches rejected r-1
        self::assertCount(1, $result->selectedRejections);
        self::assertSame('r-1', $result->selectedRejections[0]->id);

        // Outcome-driven warning should trigger for g-1
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString("Guidance 'g-1' was previously marked as HARMFUL in task 'ITPNG-100'. Reason: Caused side effects", $result->warnings[0]);
    }

    public function testDecidesThrowsOnTargetConflict(): void
    {
        $activeGuidance = [
            new RecallGuidance('g-1', 'ADD', 'skill', 'auth', ['src/Auth'], null, 'Wording 1', 'Reason 1', 'Boundary 1', [], 'approved'),
            new RecallGuidance('g-2', 'ADD', 'skill', 'auth', ['src/Auth'], null, 'Wording 2', 'Reason 2', 'Boundary 2', [], 'approved'),
        ];

        $engine = new RecallDecisionEngine();
        $task = new TaskBrief('ITPNG-123', 'Implement auth logic', ['src/Auth/OAuth.php']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Conflict: Multiple active guidance items target 'auth'");
        $engine->decide($task, $activeGuidance, [], []);
    }

    public function testDecidesThrowsOnDirectiveConflict(): void
    {
        $activeGuidance = [
            new RecallGuidance('g-1', 'ADD', 'skill', 'auth1', ['src/Auth'], null, 'Duplicate wording', 'Reason 1', 'Boundary 1', [], 'approved'),
            new RecallGuidance('g-2', 'ADD', 'skill', 'auth2', ['src/Auth'], null, 'Duplicate wording', 'Reason 2', 'Boundary 2', [], 'approved'),
        ];

        $engine = new RecallDecisionEngine();
        $task = new TaskBrief('ITPNG-123', 'Implement auth logic', ['src/Auth/OAuth.php']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Conflict: Duplicate directive text detected in multiple guidance items");
        $engine->decide($task, $activeGuidance, [], []);
    }

    public function testDecidesThrowsOnContradiction(): void
    {
        $activeGuidance = [
            new RecallGuidance('g-1', 'ADD', 'skill', 'auth', ['src/Auth'], null, 'Wording 1', 'Reason 1', 'Boundary 1', [], 'approved'),
        ];
        $rejectedGuidance = [
            new RecallRejection('r-1', 'Target is bad', ['src/Auth'], 'ADD', 'auth')
        ];

        $engine = new RecallDecisionEngine();
        $task = new TaskBrief('ITPNG-123', 'Implement auth logic', ['src/Auth/OAuth.php']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Conflict: Selected guidance 'g-1' targets 'auth', which contradicts rejected proposal 'r-1'");
        $engine->decide($task, $activeGuidance, $rejectedGuidance, []);
    }

    public function testDecidesThrowsOnStaleSkill(): void
    {
        $activeGuidance = [
            new RecallGuidance('g-1', 'ADD', 'skill', 'auth', ['src/Auth'], null, 'Wording 1', 'Reason 1', 'Boundary 1', [], 'candidate'),
        ];

        $engine = new RecallDecisionEngine();
        $task = new TaskBrief('ITPNG-123', 'Implement auth logic', ['src/Auth/OAuth.php']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Conflict: guidance 'g-1' is not approved or applied");
        $engine->decide($task, $activeGuidance, [], []);
    }

    public function testDecidesThrowsOnConstraintWithoutValidation(): void
    {
        $activeGuidance = [
            new RecallGuidance('g-1', 'ADD', 'constraint', 'auth', ['src/Auth'], null, 'Wording 1', 'Reason 1', 'Boundary 1', [], 'approved'),
        ];

        $engine = new RecallDecisionEngine();
        $task = new TaskBrief('ITPNG-123', 'Implement auth logic', ['src/Auth/OAuth.php']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Conflict: constraint 'g-1' exists but validation plan omits it.");
        $engine->decide($task, $activeGuidance, [], []);
    }

    public function testLegacyFileTargetTypeIsProjectedAsMemoryGuidance(): void
    {
        $activeGuidance = [
            new RecallGuidance('proposal.2026-06-12.001', 'ADD', 'file', 'MEMORY.md', ['src/Auth'], null, 'Wording 1', 'Reason 1', 'Boundary 1', [], 'applied'),
        ];

        $result = (new RecallDecisionEngine())->decide(
            new TaskBrief('ITPNG-123', 'Implement auth logic', ['src/Auth/OAuth.php']),
            $activeGuidance,
            [],
            [],
        );

        self::assertCount(1, $result->evaluatedGuidance);
        self::assertSame('memory', $result->evaluatedGuidance[0]->guidanceType->value);
    }

    public function testLoadsAndSelectsConstraintManifestByScope(): void
    {
        file_put_contents($this->root . '/constraints/active/constraint.project.auth.no-direct-session-access.json', json_encode([
            'schema_version' => '1.0',
            'id' => 'constraint.project.auth.no-direct-session-access',
            'engine' => 'phpstan',
            'rule_identifier' => 'project.auth.no-direct-session-access',
            'scope' => ['src/Auth'],
            'validation_commands' => ['vendor/bin/phpstan analyse'],
            'source_proposal' => 'proposal.2026-06-13.001',
            'status' => 'active',
        ], JSON_THROW_ON_ERROR));

        $constraints = (new RecallRepository())->loadConstraintManifests($this->root);
        $result = (new RecallDecisionEngine())->decide(
            new TaskBrief('ITPNG-123', 'Touch auth', ['src/Auth/Login.php']),
            [],
            [],
            [],
            $constraints,
        );

        self::assertCount(1, $result->selectedConstraints);
        self::assertSame('constraint.project.auth.no-direct-session-access', $result->selectedConstraints[0]->id);

        $validationPlan = (new RecallPromptBuilder())->buildValidationPlan(new TaskBrief('ITPNG-123', '', ['src/Auth/Login.php']), $result);
        self::assertStringContainsString('### PHPStan', $validationPlan);
        self::assertStringContainsString('vendor/bin/phpstan analyse', $validationPlan);
        self::assertStringContainsString('`project.auth.no-direct-session-access`', $validationPlan);
        self::assertStringContainsString('`proposal.2026-06-13.001`', $validationPlan);

        $meta = json_decode((new RecallPromptBuilder())->buildMetaJson(new TaskBrief('ITPNG-123', '', ['src/Auth/Login.php']), $result), true);
        self::assertSame('constraint.project.auth.no-direct-session-access', $meta['selected_constraints'][0]['id']);

        $draft = json_decode((new RecallPromptBuilder())->buildRecallLogDraft(new TaskBrief('ITPNG-123', '', ['src/Auth/Login.php']), $result), true);
        self::assertSame(['constraint.project.auth.no-direct-session-access'], $draft['constraints_used']);
        self::assertSame(['proposal.2026-06-13.001'], $draft['applied_proposals']);
    }

    public function testLoadsConstraintManifestsFromConfiguredActiveDirectory(): void
    {
        mkdir($this->root . '/active-hard-constraints', 0777, true);
        file_put_contents($this->root . '/config.json', json_encode([
            'schema_version' => '1.0',
            'active_constraints_dir' => 'active-hard-constraints',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/active-hard-constraints/constraint.project.auth.no-direct-session-access.json', json_encode([
            'schema_version' => '1.0',
            'id' => 'constraint.project.auth.no-direct-session-access',
            'engine' => 'phpstan',
            'rule_identifier' => 'project.auth.no-direct-session-access',
            'scope' => ['src/Auth'],
            'validation_commands' => ['vendor/bin/phpstan analyse'],
            'source_proposal' => 'proposal.2026-06-13.001',
            'status' => 'active',
        ], JSON_THROW_ON_ERROR));

        $constraints = (new RecallRepository())->loadConstraintManifests($this->root);

        self::assertCount(1, $constraints);
        self::assertSame('constraint.project.auth.no-direct-session-access', $constraints[0]->id);
    }

    public function testRejectsUnknownConstraintEngine(): void
    {
        file_put_contents($this->root . '/constraints/active/constraint.bad.json', json_encode([
            'schema_version' => '1.0',
            'id' => 'constraint.bad',
            'engine' => 'magic',
            'rule_identifier' => 'project.bad',
            'scope' => ['src/'],
            'validation_commands' => ['vendor/bin/phpstan analyse'],
            'source_proposal' => 'proposal.2026-06-13.001',
            'status' => 'active',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('constraint references an unknown engine: magic');
        (new RecallRepository())->loadConstraintManifests($this->root);
    }

    public function testDecidesThrowsOnUnknownRuleIdInOutcomes(): void
    {
        $outcomes = [
            [
                'task_id' => 'ITPNG-100',
                'guidance_used' => ['g-unknown'],
                'harmful' => []
            ]
        ];

        $engine = new RecallDecisionEngine();
        $task = new TaskBrief('ITPNG-123', 'Implement auth logic', ['src/Auth/OAuth.php']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Conflict: outcome references unknown rule ID 'g-unknown'");
        $engine->decide($task, [], [], $outcomes);
    }

    public function testPromptBuilderFormatsOutputs(): void
    {
        $task = new TaskBrief('ITPNG-123', 'Task description', ['src/Auth/OAuth.php']);
        $memory = "Keep repository tidy.";
        
        $selectedGuidance = [
            new RecallGuidance('g-1', 'ADD', 'skill', 'auth', ['src/Auth'], null, 'Wording 1', 'Reason 1', 'Boundary 1', ['make test'], 'approved')
        ];
        $selectedRejections = [
            new RecallRejection('r-1', 'Contradictory', ['src/Auth'], 'ADD', 'auth')
        ];
        $warnings = ["Guidance g-1 was previously harmful."];
        
        $result = new \voku\AgentRecallCompiler\RecallResult($selectedGuidance, $selectedRejections, $warnings);
        $builder = new RecallPromptBuilder();

        $systemMd = $builder->buildSystemMd($task, $memory, $result);
        self::assertStringContainsString('# L2 Meta-Prompt Briefing for Task: ITPNG-123', $systemMd);
        self::assertStringContainsString('## Repository Global Memory (`MEMORY.md`)', $systemMd);
        self::assertStringContainsString('Keep repository tidy.', $systemMd);
        self::assertStringContainsString('### Guidance: g-1', $systemMd);
        self::assertStringContainsString('Wording 1', $systemMd);
        self::assertStringContainsString('## Past Rejected Proposals (Warnings)', $systemMd);
        self::assertStringContainsString('Reason for Rejection**: *Contradictory*', $systemMd);

        $metaJson = $builder->buildMetaJson($task, $result);
        $metaData = json_decode($metaJson, true);
        self::assertSame('ITPNG-123', $metaData['task_id']);
        self::assertSame(['g-1'], $metaData['selected_guidance']);

        $validationPlan = $builder->buildValidationPlan($task, $result);
        self::assertStringContainsString('## Guidance: g-1', $validationPlan);
        self::assertStringContainsString('- make test', $validationPlan);

        $recallLog = $builder->buildRecallLogDraft($task, $result);
        $logData = json_decode($recallLog, true);
        self::assertSame('ITPNG-123', $logData['task_id']);
        self::assertSame(['g-1'], $logData['applied_proposals']);
        self::assertSame([], $logData['helpful']);
        self::assertStringContainsString('Selection alone is not proof', $logData['comment']);
    }

    public function testOutcomeStatsSeparateSelectionFromUsefulness(): void
    {
        $activeGuidance = [
            new RecallGuidance('g-1', 'ADD', 'skill', 'auth', ['src/Auth'], null, 'Wording 1', 'Reason 1', 'Boundary 1', ['make test'], 'approved'),
        ];
        $outcomes = [
            [
                'task_id' => 'ITPNG-100',
                'selected' => ['g-1'],
                'helpful' => [],
                'irrelevant' => ['g-1'],
                'harmful' => [],
                'result' => 'successful',
            ],
            [
                'task_id' => 'ITPNG-101',
                'selected' => ['g-1'],
                'helpful' => ['g-1'],
                'irrelevant' => [],
                'harmful' => [],
                'result' => 'violation_detected',
            ],
        ];

        $result = (new RecallDecisionEngine())->decide(
            new TaskBrief('ITPNG-123', 'Implement auth logic', ['src/Auth/OAuth.php']),
            $activeGuidance,
            [],
            $outcomes,
        );

        self::assertSame([
            'selected_count' => 2,
            'helpful_count' => 1,
            'irrelevant_count' => 1,
            'harmful_count' => 0,
            'violation_detected_count' => 1,
        ], $result->outcomeStats['g-1']);

        $systemMd = (new RecallPromptBuilder())->buildSystemMd(new TaskBrief('ITPNG-123', '', ['src/Auth/OAuth.php']), '', $result);
        self::assertStringContainsString('selected=2, helpful=1, irrelevant=1, harmful=0, violation_detected=1', $systemMd);
    }

    public function testPromptBuilderIncludesHardConstraintExecutionContract(): void
    {
        $task = new TaskBrief('ITPNG-123', 'Modify inline rendering', ['modules/admin/SystemSession/SystemSessionView.php']);
        $constraint = new ConstraintManifest(
            'constraint.project.inlineTemplate.renderData',
            'phpstan',
            'project.inlineTemplate.renderData',
            ['lib/application/view/', 'modules/'],
            ['make phpstan STATIC_ANALYSE_FILES="modules/admin/SystemSession/SystemSessionView.php"'],
            'proposal.2026-06-13.003',
            'active',
        );
        $result = new RecallResult([], [], [], [$constraint]);

        $systemMd = (new RecallPromptBuilder())->buildSystemMd($task, '', $result);

        self::assertStringContainsString('## Selected Hard Constraints', $systemMd);
        self::assertStringContainsString('Do not stop at prose, summaries, or recommendations', $systemMd);
        self::assertStringContainsString('### Constraint: constraint.project.inlineTemplate.renderData', $systemMd);
        self::assertStringContainsString('- **Engine**: PHPStan', $systemMd);
        self::assertStringContainsString('- **Rule identifier**: `project.inlineTemplate.renderData`', $systemMd);
        self::assertStringContainsString('make phpstan STATIC_ANALYSE_FILES="modules/admin/SystemSession/SystemSessionView.php"', $systemMd);
    }

    public function testOutcomeLoggerLogsOutcomeDraftSuccessfully(): void
    {
        // Setup proposals in directory
        $proposalData = [
            'id' => 'proposal.2026-06-08.001',
            'action' => 'ADD',
            'target_type' => 'skill',
            'target' => 'auth',
            'scope' => ['src/Auth'],
            'status' => 'approved'
        ];
        file_put_contents($this->root . '/proposals/approved/proposal.2026-06-08.001.json', json_encode($proposalData));

        $draftPath = $this->root . '/recall-log.draft.json';
        file_put_contents($draftPath, json_encode([
            'task_id' => 'ITPNG-123',
            'session' => 'session_123',
            'guidance_used' => ['proposal.2026-06-08.001'],
            'applied_proposals' => ['proposal.2026-06-08.001'],
            'helpful' => ['proposal.2026-06-08.001'],
            'irrelevant' => [],
            'harmful' => [],
            'comment' => 'It worked well'
        ]));

        $logger = new OutcomeLogger();
        $outcomeId = $logger->log($this->root, $draftPath, 'lars', 'commit_abc123');

        self::assertFileExists($this->root . '/history/outcomes.jsonl');
        $outcomesContent = file_get_contents($this->root . '/history/outcomes.jsonl');
        self::assertIsString($outcomesContent);
        
        $outcome = json_decode($outcomesContent, true);
        self::assertSame($outcomeId, $outcome['id']);
        self::assertSame('ITPNG-123', $outcome['task_id']);
        self::assertSame('lars', $outcome['actor']);
        self::assertSame('commit_abc123', $outcome['commit']);
        self::assertSame('It worked well', $outcome['comment']);
        self::assertSame('successful', $outcome['result']);
    }

    public function testOutcomeLoggerThrowsOnInvalidGuidanceReference(): void
    {
        $draftPath = $this->root . '/recall-log.draft.json';
        file_put_contents($draftPath, json_encode([
            'task_id' => 'ITPNG-123',
            'session' => 'session_123',
            'guidance_used' => ['non_existent_proposal'],
            'applied_proposals' => [],
            'helpful' => [],
            'irrelevant' => [],
            'harmful' => []
        ]));

        $logger = new OutcomeLogger();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("referenced guidance 'non_existent_proposal' does not exist");
        $logger->log($this->root, $draftPath, 'lars', 'commit_abc123');
    }

    public function testOutcomeLoggerRequiresExplicitFeedbackForSelectedGuidance(): void
    {
        $proposalData = [
            'id' => 'proposal.2026-06-08.001',
            'action' => 'ADD',
            'target_type' => 'skill',
            'target' => 'auth',
            'scope' => ['src/Auth'],
            'status' => 'approved'
        ];
        file_put_contents($this->root . '/proposals/approved/proposal.2026-06-08.001.json', json_encode($proposalData));

        $draftPath = $this->root . '/recall-log.draft.json';
        file_put_contents($draftPath, json_encode([
            'task_id' => 'ITPNG-123',
            'session' => 'session_123',
            'guidance_used' => ['proposal.2026-06-08.001'],
            'selected' => ['proposal.2026-06-08.001'],
            'applied_proposals' => ['proposal.2026-06-08.001'],
            'helpful' => [],
            'irrelevant' => [],
            'harmful' => [],
        ]));

        $logger = new OutcomeLogger();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("selected guidance 'proposal.2026-06-08.001' must be marked helpful, irrelevant, or harmful");
        $logger->log($this->root, $draftPath, 'lars', 'commit_abc123');
    }

    public function testOutcomeLoggerThrowsOnInvalidResult(): void
    {
        $draftPath = $this->root . '/recall-log.draft.json';
        file_put_contents($draftPath, json_encode([
            'task_id' => 'ITPNG-123',
            'session' => 'session_123',
            'guidance_used' => [],
            'applied_proposals' => [],
            'helpful' => [],
            'irrelevant' => [],
            'harmful' => [],
            'result' => 'incredible'
        ]));

        $logger = new OutcomeLogger();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("unsupported outcome result value in draft");
        $logger->log($this->root, $draftPath, 'lars', 'commit_abc123');
    }

    public function testCompileCommandUsesCallerSuppliedCompilationId(): void
    {
        $this->writeProposal('proposal.2026-06-18.001', 'skill', ['src/Auth']);
        $outputDir = $this->root . '/out';

        $exitCode = (new \voku\AgentRecallCompiler\Cli())->run([
            'agent-recall-compiler',
            'compile',
            '--root',
            $this->root,
            '--task',
            'PROJECT-123',
            '--description',
            'Touch auth',
            '--file',
            'src/Auth/UserService.php',
            '--output-dir',
            $outputDir,
            '--compilation-id',
            'compilation.PROJECT-123.2026-06-18.001',
        ]);

        self::assertSame(0, $exitCode);
        $meta = json_decode((string)file_get_contents($outputDir . '/meta.json'), true);
        self::assertSame('compilation.PROJECT-123.2026-06-18.001', $meta['compilation_id']);
        self::assertSame(['src/Auth/UserService.php'], $meta['task_files']);
        self::assertArrayHasKey('system.md', $meta['output_hashes']);

        $draft = json_decode((string)file_get_contents($outputDir . '/recall-log.draft.json'), true);
        self::assertSame('compilation.PROJECT-123.2026-06-18.001', $draft['compilation_id']);
        self::assertSame('proposal.2026-06-18.001', $draft['guidance_outcomes'][0]['guidance_id']);
        self::assertFalse($draft['guidance_outcomes'][0]['applied']);
        self::assertSame('unknown', $draft['guidance_outcomes'][0]['outcome']);
    }

    public function testCompileCommandGeneratesCompilationIdWhenOmitted(): void
    {
        $this->writeProposal('proposal.2026-06-18.001', 'skill', ['src/Auth']);
        $outputDir = $this->root . '/out-generated';

        $exitCode = (new \voku\AgentRecallCompiler\Cli())->run([
            'agent-recall-compiler',
            'compile',
            '--root',
            $this->root,
            '--task',
            'PROJECT-123',
            '--file',
            'src/Auth/UserService.php',
            '--output-dir',
            $outputDir,
        ]);

        self::assertSame(0, $exitCode);
        $meta = json_decode((string)file_get_contents($outputDir . '/meta.json'), true);
        self::assertIsString($meta['compilation_id']);
        self::assertStringStartsWith('compilation.PROJECT-123.', $meta['compilation_id']);
    }

    public function testDecisionRecordsEvaluatedGuidanceDeterministicallyWithReasons(): void
    {
        $activeGuidance = [
            new RecallGuidance('skill.z', 'ADD', 'skill', 'z', ['src/Z'], null, 'Z', 'Reason', 'Boundary', [], 'approved'),
            new RecallGuidance('skill.a', 'ADD', 'skill', 'a', ['src/Auth'], null, 'A', 'Reason', 'Boundary', [], 'approved'),
            new RecallGuidance('skill.global', 'ADD', 'skill', 'global', ['/'], null, 'G', 'Reason', 'Boundary', [], 'approved'),
        ];

        $result = (new RecallDecisionEngine())->decide(
            new TaskBrief('PROJECT-123', 'Touch auth', ['src/Auth/UserService.php']),
            $activeGuidance,
            [],
            [],
        );

        self::assertSame(['skill.a', 'skill.global', 'skill.z'], array_map(static fn($g) => $g->guidanceId, $result->evaluatedGuidance));
        self::assertTrue($result->evaluatedGuidance[0]->selected);
        self::assertSame('scope_overlap', $result->evaluatedGuidance[0]->selectionReason?->value);
        self::assertSame('global', $result->evaluatedGuidance[1]->selectionReason?->value);
        self::assertFalse($result->evaluatedGuidance[2]->selected);
        self::assertSame('no_scope_overlap', $result->evaluatedGuidance[2]->exclusionReason?->value);
    }

    public function testOutcomeLoggerAppendsSelectionAndOutcomeEvents(): void
    {
        $this->writeProposal('proposal.2026-06-18.001', 'skill', ['src/Auth']);
        $draftPath = $this->buildEventDraft('compilation.PROJECT-123.2026-06-18.001');
        $draft = json_decode((string)file_get_contents($draftPath), true);
        $draft['guidance_outcomes'][0]['applied'] = true;
        $draft['guidance_outcomes'][0]['outcome'] = 'helpful';
        $draft['guidance_outcomes'][0]['comment'] = 'Prevented direct session access.';
        file_put_contents($draftPath, json_encode($draft, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $result = (new OutcomeLogger())->log($this->root, $draftPath, 'Lars Moelleken', 'abc1234');

        self::assertSame('compilation.PROJECT-123.2026-06-18.001', $result);
        $selectionEvents = $this->jsonlRecords($this->root . '/history/recall-selections.jsonl');
        $outcomeEvents = $this->jsonlRecords($this->root . '/history/outcomes.jsonl');

        self::assertCount(1, $selectionEvents);
        self::assertSame('proposal.2026-06-18.001', $selectionEvents[0]['guidance_id']);
        self::assertSame('scope_overlap', $selectionEvents[0]['selection_reason']);

        self::assertCount(1, $outcomeEvents);
        self::assertStringStartsWith('guidance-outcome.', $outcomeEvents[0]['id']);
        self::assertSame('helpful', $outcomeEvents[0]['outcome']);
        self::assertTrue($outcomeEvents[0]['applied']);
        self::assertSame('abc1234', $outcomeEvents[0]['commit']);
    }

    public function testDuplicateLogOutcomeFailsWithoutPartialWrites(): void
    {
        $this->writeProposal('proposal.2026-06-18.001', 'skill', ['src/Auth']);
        $draftPath = $this->buildEventDraft('compilation.PROJECT-123.2026-06-18.001');
        $draft = json_decode((string)file_get_contents($draftPath), true);
        $draft['guidance_outcomes'][0]['outcome'] = 'not_used';
        file_put_contents($draftPath, json_encode($draft, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $logger = new OutcomeLogger();
        $logger->log($this->root, $draftPath, 'lars', 'commit_1');
        $selectionBefore = (string)file_get_contents($this->root . '/history/recall-selections.jsonl');
        $outcomesBefore = (string)file_get_contents($this->root . '/history/outcomes.jsonl');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate selection event for compilation compilation.PROJECT-123.2026-06-18.001');
        try {
            $logger->log($this->root, $draftPath, 'lars', 'commit_1');
        } finally {
            self::assertSame($selectionBefore, (string)file_get_contents($this->root . '/history/recall-selections.jsonl'));
            self::assertSame($outcomesBefore, (string)file_get_contents($this->root . '/history/outcomes.jsonl'));
        }
    }

    public function testOutcomeLoggerRejectsNonSelectedAppliedGuidance(): void
    {
        $this->writeProposal('proposal.2026-06-18.001', 'skill', ['src/Auth']);
        $this->writeProposal('proposal.2026-06-18.002', 'skill', ['src/Other']);
        $draftPath = $this->buildEventDraft('compilation.PROJECT-123.2026-06-18.001');
        $draft = json_decode((string)file_get_contents($draftPath), true);
        $draft['guidance_outcomes'][] = [
            'guidance_id' => 'proposal.2026-06-18.002',
            'guidance_type' => 'skill',
            'selected' => true,
            'applied' => true,
            'outcome' => 'helpful',
            'comment' => 'Bad row',
        ];
        file_put_contents($draftPath, json_encode($draft, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("outcome guidance 'proposal.2026-06-18.002' was not selected");
        (new OutcomeLogger())->log($this->root, $draftPath, 'lars', 'commit_1');
        self::assertFileDoesNotExist($this->root . '/history/recall-selections.jsonl');
    }

    public function testOutcomeLoggerRejectsUnknownSchemaWithoutWritingEvents(): void
    {
        $draftPath = $this->root . '/bad-recall-log.draft.json';
        file_put_contents($draftPath, json_encode([
            'schema_version' => '2.0',
            'task_id' => 'PROJECT-123',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupported outcome draft schema version: 2.0');
        (new OutcomeLogger())->log($this->root, $draftPath, 'lars', 'commit_1');
        self::assertFileDoesNotExist($this->root . '/history/recall-selections.jsonl');
        self::assertFileDoesNotExist($this->root . '/history/outcomes.jsonl');
    }

    public function testOutcomeLoggerRedactsSecretLikeOutcomeValues(): void
    {
        $this->writeProposal('proposal.2026-06-18.001', 'skill', ['src/Auth']);
        $draftPath = $this->buildEventDraft('compilation.PROJECT-123.2026-06-18.001');
        $draft = json_decode((string)file_get_contents($draftPath), true);
        $draft['guidance_outcomes'][0]['comment'] = 'token: super-secret';
        file_put_contents($draftPath, json_encode($draft, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sensitive-data match');
        (new OutcomeLogger())->log($this->root, $draftPath, 'lars', 'commit_1');
        self::assertFileDoesNotExist($this->root . '/history/recall-selections.jsonl');
    }

    public function testInlineTaskInputResolvesToTaskBriefWithScopes(): void
    {
        $brief = (new \voku\AgentRecallCompiler\InlineTaskBriefResolver())->resolve(
            ' PROJECT-123 ',
            'Touch auth',
            ['src/Auth/User.php', '', 'src/Auth/User.php'],
            ['src/Auth', ' '],
        );

        self::assertSame('PROJECT-123', $brief->id);
        self::assertSame('Touch auth', $brief->description);
        self::assertSame(['src/Auth/User.php'], $brief->files);
        self::assertSame(['src/Auth'], $brief->scopes);
    }

    public function testJsonTaskBriefResolverPreservesLegacyAndNewTaskBriefFields(): void
    {
        $briefPath = $this->root . '/task-brief-with-scopes.json';
        file_put_contents($briefPath, json_encode([
            'schema_version' => '1.0',
            'task_id' => 'PROJECT-123',
            'description' => 'Touch auth',
            'files' => ['src/Auth/User.php'],
            'scopes' => ['src/Auth'],
        ], JSON_THROW_ON_ERROR));

        $brief = (new \voku\AgentRecallCompiler\JsonTaskBriefResolver())->resolveFile($briefPath);

        self::assertSame('PROJECT-123', $brief->id);
        self::assertSame(['src/Auth/User.php'], $brief->files);
        self::assertSame(['src/Auth'], $brief->scopes);
    }

    public function testRecallRootResolverExposesTypedConfigForConfiguredConstraintDirectory(): void
    {
        file_put_contents($this->root . '/config.json', json_encode([
            'schema_version' => '1.0',
            'active_constraints_dir' => 'active-hard-constraints',
        ], JSON_THROW_ON_ERROR));

        $config = (new \voku\AgentRecallCompiler\RecallRootResolver())->resolve($this->root);

        self::assertSame($this->root, $config->root);
        self::assertSame('active-hard-constraints', $config->activeConstraintsDir);
    }

    public function testSelectionResultAdaptsHistoricalRecallResultWithoutChangingOutput(): void
    {
        $result = (new RecallDecisionEngine())->decide(
            new TaskBrief('PROJECT-123', 'Touch auth', ['src/Auth/UserService.php']),
            [new RecallGuidance('proposal.2026-06-18.001', 'ADD', 'skill', 'auth', ['src/Auth'], null, 'Use auth context.', 'Reason', null, [], 'approved')],
            [],
            [],
        );

        $selectionResult = \voku\AgentRecallCompiler\SelectionResult::fromRecallResult($result);

        self::assertSame('proposal.2026-06-18.001', $selectionResult->guidanceSelections[0]->guidanceId);
        self::assertSame(
            (new RecallPromptBuilder())->buildValidationPlan(new TaskBrief('PROJECT-123', 'Touch auth', ['src/Auth/UserService.php']), $result),
            (new \voku\AgentRecallCompiler\ValidationPlanRenderer())->render(new TaskBrief('PROJECT-123', 'Touch auth', ['src/Auth/UserService.php']), $selectionResult),
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param list<string> $scope
     */
    private function writeProposal(string $id, string $targetType, array $scope): void
    {
        file_put_contents($this->root . '/proposals/approved/' . $id . '.json', json_encode([
            'schema_version' => '1.0',
            'id' => $id,
            'created_at' => '2026-06-18T10:00:00+00:00',
            'action' => 'ADD',
            'target_type' => $targetType,
            'target' => $id,
            'scope' => $scope,
            'source_findings' => ['finding.2026-06-18.001'],
            'new' => 'Use the repository auth context for service-layer authentication.',
            'reason' => 'Repeated auth tasks need the same procedure.',
            'boundary' => 'Only service-layer auth code.',
            'validation' => ['vendor/bin/phpunit'],
            'status' => 'approved',
            'proposed_by' => 'test',
            'approved_by' => 'test',
            'approved_at' => '2026-06-18T10:10:00+00:00',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function buildEventDraft(string $compilationId): string
    {
        $result = (new RecallDecisionEngine())->decide(
            new TaskBrief('PROJECT-123', 'Touch auth', ['src/Auth/UserService.php']),
            (new RecallRepository())->loadActiveGuidance($this->root),
            [],
            [],
        );
        $draftPath = $this->root . '/recall-log.draft.json';
        file_put_contents($draftPath, (new RecallPromptBuilder())->buildRecallLogDraft(
            new TaskBrief('PROJECT-123', 'Touch auth', ['src/Auth/UserService.php']),
            $result,
            $compilationId,
        ));

        return $draftPath;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonlRecords(string $path): array
    {
        $records = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /** @var array<string, mixed> $decoded */
            $records[] = $decoded;
        }

        return $records;
    }
}
