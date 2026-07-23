<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Command;

use voku\AgentRecallCompiler\FeedbackAssessmentRenderer;
use voku\AgentRecallCompiler\FeedbackParser;
use voku\AgentRecallCompiler\InlineTaskBriefResolver;
use voku\AgentRecallCompiler\JsonTaskBriefResolver;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\Compilation\RecallCompilationService;
use voku\AgentRecallCompiler\Provider\LearningRecallProvider;
use voku\AgentRecallCompiler\Provider\KanbanContextRecallProvider;
use voku\AgentRecallCompiler\Provider\MapRecallProvider;
use voku\AgentRecallCompiler\Provider\MemoryRecallProvider;
use voku\AgentRecallCompiler\Provider\ScopedDocumentRecallProvider;
use voku\AgentRecallCompiler\Provider\TaskContextRecallProvider;
use voku\AgentRecallCompiler\RecallCompilationBlockedException;
use voku\AgentRecallCompiler\RecallPromptBuilder;
use voku\AgentRecallCompiler\RecallRepository;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\RecallRootResolver;

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

        $briefPath = $parsed->stringOption('task-brief');
        if ($briefPath !== null) {
            $task = (new JsonTaskBriefResolver())->resolveFile($briefPath);
        } else {
            $taskId = $parsed->stringOption('task');
            if ($taskId === null || trim($taskId) === '') {
                throw new \InvalidArgumentException('compile requires --task-brief or inline option --task');
            }
            $task = (new InlineTaskBriefResolver())->resolve(
                $taskId,
                $parsed->stringOption('description') ?? '',
                $parsed->stringOptions('file'),
                tags: $parsed->stringOptions('tag'),
            );
        }

        $outputDir = $parsed->stringOption('output-dir') ?? '.';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $outputDir));
        }

        $compilationId = $parsed->stringOption('compilation-id') ?? $this->generateCompilationId($task->id);

        $feedbackPath = $parsed->stringOption('feedback');
        $feedback = ($feedbackPath !== null && trim($feedbackPath) !== '')
            ? (new FeedbackParser())->parseFile($feedbackPath)
            : null;

        try {
            $repository = new RecallRepository();
            $providers = [
                new TaskContextRecallProvider(),
                new MemoryRecallProvider($repository),
                new LearningRecallProvider($repository),
            ];
            $mapIndex = $parsed->stringOption('map-index');
            if ($mapIndex !== null) {
                $providers[] = new MapRecallProvider($mapIndex, $parsed->stringOption('map-root'));
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
        ];
        $systemMd = $this->promptBuilder->buildSystemMd($task, $this->memoryFromFacts($compilation->facts), $result, $feedback, $compilation->facts, $bundleDigest);
        $validationPlan = $this->promptBuilder->buildValidationPlan($task, $result);
        $logDraft = $this->promptBuilder->buildRecallLogDraft($task, $result, $compilationId);
        $bundleJson = CanonicalJson::pretty($bundle);
        $factsJson = CanonicalJson::pretty($facts);
        $selectionJson = CanonicalJson::pretty($selectionReport);

        // recall-log.draft.json and feedback-assessment.draft.json are
        // *meant* to be hand-edited after compile (guidance_outcomes /
        // review verdicts), so they are deliberately excluded from
        // output_hashes: that set is tamper-evidence for files that should
        // never change post-compile, and hashing an edit-by-design file
        // there would make every correctly-completed task permanently fail
        // agent-loop verify's staleness check.
        $outputHashes = [
            'system.md' => hash('sha256', $systemMd),
            'validation-plan.md' => hash('sha256', $validationPlan),
            'recall.bundle.json' => hash('sha256', $bundleJson),
            'facts.json' => hash('sha256', $factsJson),
            'selection-report.json' => hash('sha256', $selectionJson),
        ];

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
        if ($feedbackAssessment !== null) {
            $this->writeFile($outputDir . '/feedback-assessment.draft.json', $feedbackAssessment);
        }

        fwrite(\STDOUT, sprintf("Briefing compiled successfully under: %s/\n", rtrim($outputDir, '/')));
        fwrite(\STDOUT, sprintf("- compilation ID: %s\n", $compilationId));
        fwrite(\STDOUT, sprintf("- system.md (selected guidance: %d, selected constraints: %d)\n", count($result->selectedGuidance), count($result->selectedConstraints)));
        fwrite(\STDOUT, "- recall.bundle.json (canonical, replayable)\n");
        fwrite(\STDOUT, "- facts.json and selection-report.json\n");
        fwrite(\STDOUT, "- validation-plan.md\n");
        fwrite(\STDOUT, "- recall-log.draft.json\n");
        if ($feedbackAssessment !== null) {
            fwrite(\STDOUT, "- feedback-assessment.draft.json (untrusted peer feedback to verify)\n");
        }

        return 0;
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Unable to write compile artifact: ' . $path);
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
