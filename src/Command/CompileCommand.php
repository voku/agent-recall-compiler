<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Command;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\Compilation\RecallCompilationService;
use voku\AgentRecallCompiler\FeedbackAssessmentRenderer;
use voku\AgentRecallCompiler\FeedbackParser;
use voku\AgentRecallCompiler\InlineTaskBriefResolver;
use voku\AgentRecallCompiler\JsonTaskBriefResolver;
use voku\AgentRecallCompiler\Provider\KanbanContextRecallProvider;
use voku\AgentRecallCompiler\Provider\LearningRecallProvider;
use voku\AgentRecallCompiler\Provider\MapRecallProvider;
use voku\AgentRecallCompiler\Provider\MemoryRecallProvider;
use voku\AgentRecallCompiler\Provider\ScopedDocumentRecallProvider;
use voku\AgentRecallCompiler\Provider\TaskContextRecallProvider;
use voku\AgentRecallCompiler\RecallCompilationBlockedException;
use voku\AgentRecallCompiler\RecallPromptBuilder;
use voku\AgentRecallCompiler\RecallRepository;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\RecallRootResolver;
use voku\AgentRecallCompiler\TaskBrief;
use voku\AgentRecallCompiler\Verification\CompiledVerificationPlan;
use voku\AgentRecallCompiler\Verification\VerificationArtifactWriter;
use voku\AgentRecallCompiler\Verification\VerificationContextLoader;
use voku\AgentRecallCompiler\Verification\VerificationPlanCompiler;

final class CompileCommand
{
    private readonly RecallRootResolver $rootResolver;
    private readonly RecallPromptBuilder $promptBuilder;
    private readonly OptionParser $optionParser;

    public function __construct()
    {
        $this->rootResolver = new RecallRootResolver();
        $this->promptBuilder = new RecallPromptBuilder();
        $this->optionParser = new OptionParser();
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        $parsed = $this->optionParser->parse($tokens);
        $rootConfig = $this->rootResolver->resolve($parsed->stringOption('root'));

        $targets = $parsed->stringOptions('target');
        $briefPath = $parsed->stringOption('task-brief');
        if ($briefPath !== null) {
            $task = (new JsonTaskBriefResolver())->resolveFile($briefPath);
            $task = $this->withAdditionalTargets($task, $targets);
        } else {
            $taskId = $parsed->stringOption('task');
            if ($taskId === null || trim($taskId) === '') {
                throw new InvalidArgumentException('compile requires --task-brief or inline option --task');
            }
            $task = (new InlineTaskBriefResolver())->resolve(
                $taskId,
                $parsed->stringOption('description') ?? '',
                $parsed->stringOptions('file'),
                tags: $parsed->stringOptions('tag'),
                targets: $targets,
            );
        }

        $outputDir = $parsed->stringOption('output-dir') ?? '.';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $outputDir));
        }

        $compilationId = $parsed->stringOption('compilation-id') ?? $this->generateCompilationId($task->id);

        $feedbackPath = $parsed->stringOption('feedback');
        $feedback = ($feedbackPath !== null && trim($feedbackPath) !== '')
            ? (new FeedbackParser())->parseFile($feedbackPath)
            : null;

        $mapIndex = $parsed->stringOption('map-index');
        $mapRoot = $parsed->stringOption('map-root');
        $mapPolicy = new EditContextPolicy(
            focusTerms: $parsed->stringOptions('edit-focus'),
            includeRelatedContext: $parsed->stringOptions('edit-focus') === [],
        );

        try {
            $repository = new RecallRepository();
            $providers = [
                new TaskContextRecallProvider(),
                new MemoryRecallProvider($repository),
                new LearningRecallProvider($repository),
            ];
            if ($task->targets !== [] && $mapIndex === null) {
                throw new InvalidArgumentException('compile targets require --map-index');
            }
            if ($mapIndex !== null) {
                $providers[] = new MapRecallProvider($mapIndex, $mapRoot, $mapPolicy);
            }
            $kanbanContext = $parsed->stringOption('kanban-context');
            if ($kanbanContext !== null) {
                $providers[] = new KanbanContextRecallProvider($kanbanContext);
            }
            foreach ($parsed->stringOptions('document-manifest') as $manifestPath) {
                $providers[] = new ScopedDocumentRecallProvider($manifestPath);
            }
            $compilation = (new RecallCompilationService($providers))->compile($task, $rootConfig);
            $result = $compilation->result;
        } catch (RecallCompilationBlockedException $e) {
            $blockedMeta = $this->promptBuilder->buildMetaJson(
                $task,
                new RecallResult([], [], [$e->getMessage()]),
                $compilationId,
                [],
                true,
                $e->getMessage(),
            );
            $this->writeFile($outputDir . '/meta.json', $blockedMeta);

            throw $e;
        }

        $verification = $this->compileVerification($task, $result, $mapIndex, $mapRoot, $mapPolicy);
        $verificationWriter = new VerificationArtifactWriter();

        $bundle = $compilation->bundle;
        $bundleDigest = CanonicalJson::digest($bundle);
        $facts = [
            'schema_version' => '1.0',
            'bundle_sha256' => $bundleDigest,
            'facts' => $compilation->facts,
        ];
        $selectionReport = [
            'schema_version' => '1.0',
            'bundle_sha256' => $bundleDigest,
            'evaluated_guidance' => $bundle['evaluated_guidance'],
            'selected_guidance' => $bundle['selected_guidance'],
            'selected_constraints' => $bundle['selected_constraints'],
            'selected_rejections' => $bundle['selected_rejections'],
            'warnings' => $bundle['warnings'],
            'effective_scope' => $compilation->effectiveScope,
        ];
        $systemMd = $this->promptBuilder->buildSystemMd(
            $task,
            $this->memoryFromFacts($compilation->facts),
            $result,
            $feedback,
            $compilation->facts,
            $bundleDigest,
        );
        $validationPlan = $this->promptBuilder->buildValidationPlan($compilation->effectiveTask, $result);
        /** @var array{plan: string, key: string}|null $verificationArtifacts */
        $verificationArtifacts = null;
        if ($verification !== null) {
            $systemMd = rtrim($systemMd) . "\n\n" . $verificationWriter->renderQuestionsMarkdown($verification);
            $validationPlan = rtrim($validationPlan) . "\n\n" . $verificationWriter->renderValidationMarkdown($verification);
            $verificationArtifacts = [
                'plan' => $verificationWriter->renderPlan($verification),
                'key' => $verificationWriter->renderKey($verification),
            ];
        }
        $logDraft = $this->promptBuilder->buildRecallLogDraft($task, $result, $compilationId);
        $bundleJson = CanonicalJson::pretty($bundle);
        $factsJson = CanonicalJson::pretty($facts);
        $selectionJson = CanonicalJson::pretty($selectionReport);

        // Draft files are edited after compilation and therefore deliberately
        // excluded. Immutable verification artifacts are included, including
        // the verifier-owned key, so stale keys cannot silently survive a map change.
        $outputHashes = [
            'system.md' => hash('sha256', $systemMd),
            'validation-plan.md' => hash('sha256', $validationPlan),
            'recall.bundle.json' => hash('sha256', $bundleJson),
            'facts.json' => hash('sha256', $factsJson),
            'selection-report.json' => hash('sha256', $selectionJson),
        ];
        if ($verificationArtifacts !== null) {
            $outputHashes['verification-plan.json'] = hash('sha256', $verificationArtifacts['plan']);
            $outputHashes['verification-key.json'] = hash('sha256', $verificationArtifacts['key']);
        }

        $feedbackAssessment = null;
        if ($feedback !== null && !$feedback->isEmpty()) {
            $feedbackAssessment = (new FeedbackAssessmentRenderer())->render($task, $feedback, $compilationId);
        }

        $metaJson = $this->promptBuilder->buildMetaJson(
            $task,
            $result,
            $compilationId,
            $outputHashes,
            bundleDigest: $bundleDigest,
            snapshotDigest: $compilation->snapshot->digest(),
        );
        if ($verification !== null && $verificationArtifacts !== null) {
            $metaJson = $this->withVerificationMetadata(
                $metaJson,
                $verification,
                $verificationArtifacts['plan'],
                $verificationArtifacts['key'],
            );
        }

        $this->writeFile($outputDir . '/system.md', $systemMd);
        $this->writeFile($outputDir . '/meta.json', $metaJson);
        $this->writeFile($outputDir . '/validation-plan.md', $validationPlan);
        $this->writeFile($outputDir . '/recall.bundle.json', $bundleJson);
        $this->writeFile($outputDir . '/facts.json', $factsJson);
        $this->writeFile($outputDir . '/selection-report.json', $selectionJson);
        $this->writeFile($outputDir . '/recall-log.draft.json', $logDraft);
        $this->writeFile($outputDir . '/compilation-receipt.json', CanonicalJson::pretty([
            'schema_version' => '1.0',
            'compilation_id' => $compilationId,
            'bundle_sha256' => $bundleDigest,
            'created_at' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
        ]));
        if ($verification !== null) {
            $verificationWriter->write($outputDir, $verification);
        }
        if ($feedbackAssessment !== null) {
            $this->writeFile($outputDir . '/feedback-assessment.draft.json', $feedbackAssessment);
        }

        fwrite(\STDOUT, sprintf("Briefing compiled successfully under: %s/\n", rtrim($outputDir, '/')));
        fwrite(\STDOUT, sprintf("- compilation ID: %s\n", $compilationId));
        fwrite(\STDOUT, sprintf("- system.md (selected guidance: %d, selected constraints: %d)\n", count($result->selectedGuidance), count($result->selectedConstraints)));
        fwrite(\STDOUT, "- recall.bundle.json (canonical, replayable)\n");
        fwrite(\STDOUT, "- facts.json and selection-report.json\n");
        fwrite(\STDOUT, "- validation-plan.md\n");
        if ($verification !== null) {
            fwrite(\STDOUT, "- verification-plan.json (public questions, checklist, and gates)\n");
            fwrite(\STDOUT, "- verification-key.json (verifier-owned expected answers)\n");
        }
        fwrite(\STDOUT, "- recall-log.draft.json\n");
        if ($feedbackAssessment !== null) {
            fwrite(\STDOUT, "- feedback-assessment.draft.json (untrusted peer feedback to verify)\n");
        }

        return 0;
    }

    private function compileVerification(
        TaskBrief $task,
        RecallResult $result,
        ?string $mapIndex,
        ?string $mapRoot,
        EditContextPolicy $mapPolicy,
    ): ?CompiledVerificationPlan {
        // The v1 schema has one canonical target. Existing repeatable target
        // compilation remains compatible; it simply retains the pre-v1 artifact set.
        if ($mapIndex === null || count($task->targets) !== 1) {
            return null;
        }

        $context = (new VerificationContextLoader())->load(
            $mapIndex,
            $mapRoot,
            $mapPolicy,
            $task->targets[0],
        );

        return (new VerificationPlanCompiler($context->map))->compile(
            $task,
            $context->editContext,
            $result,
        );
    }

    private function withVerificationMetadata(
        string $metaJson,
        CompiledVerificationPlan $verification,
        string $planJson,
        string $keyJson,
    ): string {
        $meta = json_decode($metaJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($meta)) {
            throw new LogicException('Compiled metadata must decode to an object.');
        }
        /** @var array<string, mixed> $meta */
        $meta['verification_plan_sha256'] = 'sha256:' . hash('sha256', $planJson);
        $meta['verification_key_sha256'] = 'sha256:' . hash('sha256', $keyJson);
        $meta['verification_generator'] = $verification->plan->generator;

        return CanonicalJson::pretty($meta);
    }

    /** @param list<string> $targets */
    private function withAdditionalTargets(TaskBrief $task, array $targets): TaskBrief
    {
        $merged = $task->targets;
        foreach ($targets as $target) {
            $target = trim($target);
            if ($target !== '' && !in_array($target, $merged, true)) {
                $merged[] = $target;
            }
        }

        if ($merged === $task->targets) {
            return $task;
        }

        return new TaskBrief(
            id: $task->id,
            description: $task->description,
            files: $task->files,
            scopes: $task->scopes,
            nonGoals: $task->nonGoals,
            validation: $task->validation,
            status: $task->status,
            revision: $task->revision,
            sourcePath: $task->sourcePath,
            tags: $task->tags,
            behaviorAnchors: $task->behaviorAnchors,
            targets: $merged,
        );
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Unable to write compile artifact: ' . $path);
        }
    }

    private function generateCompilationId(string $taskId): string
    {
        $safeTaskId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $taskId);
        if (!is_string($safeTaskId) || trim($safeTaskId) === '') {
            $safeTaskId = 'task';
        }

        return sprintf('compilation.%s.%s.%s', trim($safeTaskId, '-'), gmdate('Y-m-d-His'), bin2hex(random_bytes(4)));
    }

    /** @param list<array<string, mixed>> $facts */
    private function memoryFromFacts(array $facts): string
    {
        foreach ($facts as $fact) {
            if (($fact['type'] ?? null) !== 'memory') {
                continue;
            }
            $content = $fact['payload']['content'] ?? null;
            if (is_string($content)) {
                return $content;
            }
        }

        return '';
    }
}
