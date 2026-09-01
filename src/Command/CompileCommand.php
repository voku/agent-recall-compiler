<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Command;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\Compilation\RecallCompilationService;
use voku\AgentRecallCompiler\Context\ContextExplainProjector;
use voku\AgentRecallCompiler\Context\LearningPrecedentExplainProjector;
use voku\AgentRecallCompiler\FeedbackAssessmentRenderer;
use voku\AgentRecallCompiler\FeedbackParser;
use voku\AgentRecallCompiler\InlineTaskBriefResolver;
use voku\AgentRecallCompiler\JsonTaskBriefResolver;
use voku\AgentRecallCompiler\OperatingPromptOutcomeDraftAugmenter;
use voku\AgentRecallCompiler\OperatingPromptRequest;
use voku\AgentRecallCompiler\Provider\KanbanContextRecallProvider;
use voku\AgentRecallCompiler\Provider\LearningNoteRecallProvider;
use voku\AgentRecallCompiler\Provider\LearningRecallProvider;
use voku\AgentRecallCompiler\Provider\MapRecallProvider;
use voku\AgentRecallCompiler\Provider\MemoryRecallProvider;
use voku\AgentRecallCompiler\Provider\OperatingPromptRecallProvider;
use voku\AgentRecallCompiler\Provider\ProjectCapabilityRecallProvider;
use voku\AgentRecallCompiler\Provider\ScopedDocumentRecallProvider;
use voku\AgentRecallCompiler\Provider\TaskContextRecallProvider;
use voku\AgentRecallCompiler\RecallCompilationBlockedException;
use voku\AgentRecallCompiler\RecallPromptBuilder;
use voku\AgentRecallCompiler\RecallRepository;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\RecallRootResolver;
use voku\AgentRecallCompiler\Rendering\ContextExplainRenderer;
use voku\AgentRecallCompiler\Rendering\LearningPrecedentRenderer;
use voku\AgentRecallCompiler\Rendering\OperatingPromptRenderer;
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

    public function __construct(private readonly bool $reportToStdout = true)
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
        $inlineOperatingPrompts = $this->parseOperatingPrompts($parsed->stringOptions('operating-prompt'));
        $briefPath = $parsed->stringOption('task-brief');
        if ($briefPath !== null) {
            $task = (new JsonTaskBriefResolver())->resolveFile($briefPath);
            $task = $this->withAdditionalTargets($task, $targets);
            $task = $this->withAdditionalOperatingPrompts($task, $inlineOperatingPrompts);
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
                operatingPrompts: $inlineOperatingPrompts,
            );
        }

        $operatingPromptManifests = $parsed->stringOptions('operating-prompt-manifest');
        if ($task->operatingPrompts !== [] && $operatingPromptManifests === []) {
            throw new InvalidArgumentException('compile operating prompts require at least one --operating-prompt-manifest');
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
        $mapSearchIndex = $parsed->stringOption('map-search-index');
        $mapSearchLimitOption = $parsed->stringOption('map-search-limit');
        $mapSearchLimit = $mapSearchLimitOption === null ? 8 : (int) $mapSearchLimitOption;
        if ($mapSearchLimit < 1) {
            throw new InvalidArgumentException('compile --map-search-limit must be a positive integer');
        }
        if ($mapSearchIndex !== null && $mapIndex === null) {
            throw new InvalidArgumentException('compile --map-search-index requires --map-index');
        }
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
                new LearningNoteRecallProvider(),
            ];
            if ($rootConfig->projectRoot !== null && $this->hasProjectCapabilityEvidence($rootConfig->projectRoot)) {
                $providers[] = new ProjectCapabilityRecallProvider($rootConfig->projectRoot);
            }
            if ($task->operatingPrompts !== []) {
                $providers[] = new OperatingPromptRecallProvider($operatingPromptManifests);
            }
            if ($task->targets !== [] && $mapIndex === null) {
                throw new InvalidArgumentException('compile targets require --map-index');
            }
            if ($mapIndex !== null) {
                $providers[] = new MapRecallProvider(
                    $mapIndex,
                    $mapRoot,
                    $mapPolicy,
                    searchDatabase: $mapSearchIndex,
                    searchLimit: $mapSearchLimit,
                );
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

        $verification = $this->compileVerification($task, $result, $compilation->facts, $mapIndex, $mapRoot, $mapPolicy);
        $verificationWriter = new VerificationArtifactWriter();

        $bundle = $compilation->bundle;
        $bundleDigest = CanonicalJson::digest($bundle);
        $contextExplain = (new ContextExplainProjector())->project(
            $compilation->effectiveTask,
            $compilation->facts,
            $result,
        );
        array_push(
            $contextExplain,
            ...(new LearningPrecedentExplainProjector())->project($compilation->facts, $result),
        );
        usort($contextExplain, static fn (array $left, array $right): int => ($left['id'] ?? '') <=> ($right['id'] ?? ''));
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
            'context_explain' => $contextExplain,
        ];
        $systemMd = $this->promptBuilder->buildSystemMd(
            $task,
            $this->memoryFromFacts($compilation->facts),
            $result,
            $feedback,
            $compilation->facts,
            $bundleDigest,
        );
        $precedentContext = (new LearningPrecedentRenderer())->render($compilation->facts, $result);
        if ($precedentContext !== '') {
            $systemMd = rtrim($systemMd) . "\n\n" . $precedentContext;
        }
        $contextExplainMd = (new ContextExplainRenderer())->render($contextExplain);
        if ($contextExplainMd !== '') {
            $systemMd = rtrim($systemMd) . "\n\n" . $contextExplainMd;
        }
        $operatingContract = (new OperatingPromptRenderer())->render($compilation->facts);
        if ($operatingContract !== '') {
            $systemMd = rtrim($systemMd) . "\n\n" . $operatingContract;
        }
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
        $logDraft = (new OperatingPromptOutcomeDraftAugmenter())->augment(
            $this->promptBuilder->buildRecallLogDraft($task, $result, $compilationId),
            $task,
        );
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
        if ($verification !== null) {
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
        } else {
            $this->removeFileIfPresent($outputDir . '/verification-plan.json');
            $this->removeFileIfPresent($outputDir . '/verification-key.json');
        }
        if ($feedbackAssessment !== null) {
            $this->writeFile($outputDir . '/feedback-assessment.draft.json', $feedbackAssessment);
        }

        if ($this->reportToStdout) {
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
        }

        return 0;
    }

    /** @param list<array<string, mixed>> $facts */
    private function compileVerification(
        TaskBrief $task,
        RecallResult $result,
        array $facts,
        ?string $mapIndex,
        ?string $mapRoot,
        EditContextPolicy $mapPolicy,
    ): ?CompiledVerificationPlan {
        if ($mapIndex === null || count($task->targets) !== 1) {
            return null;
        }

        $context = (new VerificationContextLoader())->load(
            $mapIndex,
            $mapRoot,
            $mapPolicy,
            $task->targets[0],
            $this->mapDigestFromFacts($facts),
        );

        return (new VerificationPlanCompiler($context->map))->compile(
            $task,
            $context->editContext,
            $result,
        );
    }

    /** @param list<array<string, mixed>> $facts */
    private function mapDigestFromFacts(array $facts): string
    {
        foreach ($facts as $fact) {
            if (($fact['id'] ?? null) !== 'map.snapshot') {
                continue;
            }
            $payload = is_array($fact['payload'] ?? null) ? $fact['payload'] : [];
            $digest = $payload['map_digest'] ?? null;
            if (is_string($digest) && $digest !== '') {
                return $digest;
            }
        }

        throw new LogicException('Target-aware verification requires the compiled map snapshot digest.');
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
            operatingPrompts: $task->operatingPrompts,
            governedRun: $task->governedRun,
            acceptanceCriteria: $task->acceptanceCriteria,
        );
    }

    /** @param list<OperatingPromptRequest> $additional */
    private function withAdditionalOperatingPrompts(TaskBrief $task, array $additional): TaskBrief
    {
        if ($additional === []) {
            return $task;
        }

        $merged = $task->operatingPrompts;
        $seen = [];
        foreach ($merged as $request) {
            $seen[$request->id] = true;
        }
        foreach ($additional as $request) {
            if (isset($seen[$request->id])) {
                throw new InvalidArgumentException('task selects operating prompt more than once: ' . $request->id);
            }
            $seen[$request->id] = true;
            $merged[] = $request;
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
            targets: $task->targets,
            operatingPrompts: $merged,
            governedRun: $task->governedRun,
            acceptanceCriteria: $task->acceptanceCriteria,
        );
    }

    /**
     * @param list<string> $jsonRequests
     * @return list<OperatingPromptRequest>
     */
    private function parseOperatingPrompts(array $jsonRequests): array
    {
        $requests = [];
        $seen = [];
        foreach ($jsonRequests as $jsonRequest) {
            try {
                $data = json_decode($jsonRequest, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new InvalidArgumentException('invalid --operating-prompt JSON: ' . $exception->getMessage(), 0, $exception);
            }
            if (!is_array($data)) {
                throw new InvalidArgumentException('--operating-prompt must be a JSON object');
            }
            try {
                /** @var array<string, mixed> $data */
                $request = OperatingPromptRequest::fromArray($data);
            } catch (\InvalidArgumentException $exception) {
                throw new InvalidArgumentException('invalid --operating-prompt: ' . $exception->getMessage(), 0, $exception);
            }
            if (isset($seen[$request->id])) {
                throw new InvalidArgumentException('inline operating prompt selected more than once: ' . $request->id);
            }
            $seen[$request->id] = true;
            $requests[] = $request;
        }

        return $requests;
    }

    private function hasProjectCapabilityEvidence(string $projectRoot): bool
    {
        $root = rtrim($projectRoot, '/\\');

        return is_file($root . '/composer.json') || is_dir($root . '/.github/workflows');
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Unable to write compile artifact: ' . $path);
        }
    }

    private function removeFileIfPresent(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!unlink($path)) {
            throw new RuntimeException('Unable to remove stale verification artifact: ' . $path);
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
