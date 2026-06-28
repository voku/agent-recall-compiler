<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Command;

use voku\AgentRecallCompiler\FeedbackAssessmentRenderer;
use voku\AgentRecallCompiler\FeedbackParser;
use voku\AgentRecallCompiler\InlineTaskBriefResolver;
use voku\AgentRecallCompiler\JsonTaskBriefResolver;
use voku\AgentRecallCompiler\RecallCompilationBlockedException;
use voku\AgentRecallCompiler\RecallDecisionEngine;
use voku\AgentRecallCompiler\RecallPromptBuilder;
use voku\AgentRecallCompiler\RecallRepository;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\RecallRootResolver;

final class CompileCommand
{
    private readonly RecallRootResolver $rootResolver;
    private readonly RecallRepository $repository;
    private readonly RecallDecisionEngine $decisionEngine;
    private readonly RecallPromptBuilder $promptBuilder;
    private readonly OptionParser $optionParser;

    public function __construct()
    {
        $this->rootResolver = new RecallRootResolver();
        $this->repository = new RecallRepository();
        $this->decisionEngine = new RecallDecisionEngine();
        $this->promptBuilder = new RecallPromptBuilder();
        $this->optionParser = new OptionParser();
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        $parsed = $this->optionParser->parse($tokens);
        $rootConfig = $this->rootResolver->resolve($parsed->stringOption('root'));
        $root = $rootConfig->root;

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
            );
        }

        $outputDir = $parsed->stringOption('output-dir') ?? '.';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $outputDir));
        }

        $compilationId = $parsed->stringOption('compilation-id') ?? $this->generateCompilationId($task->id);

        $memory = $this->repository->loadMemory($root);
        $activeGuidance = $this->repository->loadActiveGuidance($root);
        $rejectedGuidance = $this->repository->loadRejectedGuidance($root);
        $constraints = $this->repository->loadConstraintManifests($root);
        $outcomes = $this->repository->loadOutcomes($root);
        $retiredProposalIds = $this->repository->loadRetiredProposalIds($root);

        $feedbackPath = $parsed->stringOption('feedback');
        $feedback = ($feedbackPath !== null && trim($feedbackPath) !== '')
            ? (new FeedbackParser())->parseFile($feedbackPath)
            : null;

        try {
            $result = $this->decisionEngine->decide($task, $activeGuidance, $rejectedGuidance, $outcomes, $constraints, $retiredProposalIds);
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

        $systemMd = $this->promptBuilder->buildSystemMd($task, $memory, $result, $feedback);
        $validationPlan = $this->promptBuilder->buildValidationPlan($task, $result);
        $logDraft = $this->promptBuilder->buildRecallLogDraft($task, $result, $compilationId);

        $outputHashes = [
            'system.md' => hash('sha256', $systemMd),
            'validation-plan.md' => hash('sha256', $validationPlan),
            'recall-log.draft.json' => hash('sha256', $logDraft),
        ];

        $feedbackAssessment = null;
        if ($feedback !== null && !$feedback->isEmpty()) {
            $feedbackAssessment = (new FeedbackAssessmentRenderer())->render($task, $feedback, $compilationId);
            $outputHashes['feedback-assessment.draft.json'] = hash('sha256', $feedbackAssessment);
        }

        $metaJson = $this->promptBuilder->buildMetaJson($task, $result, $compilationId, $outputHashes);

        $this->writeFile($outputDir . '/system.md', $systemMd);
        $this->writeFile($outputDir . '/meta.json', $metaJson);
        $this->writeFile($outputDir . '/validation-plan.md', $validationPlan);
        $this->writeFile($outputDir . '/recall-log.draft.json', $logDraft);
        if ($feedbackAssessment !== null) {
            $this->writeFile($outputDir . '/feedback-assessment.draft.json', $feedbackAssessment);
        }

        fwrite(\STDOUT, sprintf("Briefing compiled successfully under: %s/\n", rtrim($outputDir, '/')));
        fwrite(\STDOUT, sprintf("- compilation ID: %s\n", $compilationId));
        fwrite(\STDOUT, sprintf("- system.md (selected guidance: %d, selected constraints: %d)\n", count($result->selectedGuidance), count($result->selectedConstraints)));
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
}
