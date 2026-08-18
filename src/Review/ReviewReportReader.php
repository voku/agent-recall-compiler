<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

use JsonException;
use RuntimeException;

/**
 * Reads the current persisted blind-spot report without creating or mutating review state.
 */
final readonly class ReviewReportReader
{
    private ReviewReportPaths $paths;

    public function __construct(string $workspacePath)
    {
        $this->paths = new ReviewReportPaths($workspacePath);
    }

    public function read(string $taskId, string $outputDir): ?ReviewReportArtifact
    {
        $path = $this->paths->json($taskId, $outputDir);
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read review report JSON: ' . $path);
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid review report JSON ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Review report JSON must decode to an object: ' . $path);
        }
        /** @var array<string, mixed> $data */

        $report = $this->parseReport($data, $taskId, $path);

        return new ReviewReportArtifact(
            report: $report,
            jsonPath: $path,
            sha256: 'sha256:' . hash('sha256', $contents),
        );
    }

    /** @param array<string, mixed> $data */
    private function parseReport(array $data, string $expectedTaskId, string $path): ReviewReport
    {
        if (($data['version'] ?? null) !== ReviewReport::VERSION) {
            throw new RuntimeException('Unsupported review report version: ' . $path);
        }

        $taskId = $data['task_id'] ?? null;
        if (!is_string($taskId) || $taskId !== $expectedTaskId) {
            throw new RuntimeException('Review report task_id does not match the requested task: ' . $path);
        }

        $rawFindings = $data['findings'] ?? null;
        if (!is_array($rawFindings) || !array_is_list($rawFindings)) {
            throw new RuntimeException('Review report findings must be a list: ' . $path);
        }

        $findings = [];
        foreach ($rawFindings as $index => $rawFinding) {
            if (!is_array($rawFinding)) {
                throw new RuntimeException(sprintf('Review report finding %d must be an object: %s', $index, $path));
            }
            /** @var array<string, mixed> $rawFinding */
            $findings[] = $this->parseFinding($rawFinding, $index, $path);
        }

        if (
            !array_key_exists('contract_revision', $data)
            || !array_key_exists('implementation_snapshot', $data)
        ) {
            throw new RuntimeException(
                'Review report requires contract_revision and implementation_snapshot fields: ' . $path,
            );
        }

        $contractRevision = $data['contract_revision'];
        if ($contractRevision !== null && !is_int($contractRevision)) {
            throw new RuntimeException('Review report contract_revision must be an integer or null: ' . $path);
        }
        $implementationSnapshot = $data['implementation_snapshot'];
        if ($implementationSnapshot !== null && !is_string($implementationSnapshot)) {
            throw new RuntimeException('Review report implementation_snapshot must be a string or null: ' . $path);
        }

        $report = new ReviewReport(
            taskId: $taskId,
            findings: $findings,
            contractRevision: $contractRevision,
            implementationSnapshot: $implementationSnapshot,
        );

        $storedStatus = $data['status'] ?? null;
        if (!is_string($storedStatus) || $storedStatus !== $report->status()) {
            throw new RuntimeException('Review report status does not match its findings: ' . $path);
        }

        return $report;
    }

    /** @param array<string, mixed> $data */
    private function parseFinding(array $data, int $index, string $path): BlindSpotFinding
    {
        $id = $data['id'] ?? null;
        $severity = $data['severity'] ?? null;
        $message = $data['message'] ?? null;
        $evidence = $data['evidence'] ?? null;

        if (!is_string($id) || $id === '') {
            throw new RuntimeException(sprintf('Review report finding %d requires a non-empty id: %s', $index, $path));
        }
        $parsedSeverity = is_string($severity) ? ReviewSeverity::tryFrom($severity) : null;
        if (!$parsedSeverity instanceof ReviewSeverity) {
            throw new RuntimeException(sprintf('Review report finding %d has invalid severity: %s', $index, $path));
        }
        if (!is_string($message)) {
            throw new RuntimeException(sprintf('Review report finding %d requires a string message: %s', $index, $path));
        }
        if (!is_array($evidence) || !array_is_list($evidence)) {
            throw new RuntimeException(sprintf('Review report finding %d evidence must be a list: %s', $index, $path));
        }

        $typedEvidence = [];
        foreach ($evidence as $evidenceIndex => $item) {
            if (!is_string($item)) {
                throw new RuntimeException(sprintf(
                    'Review report finding %d evidence %d must be a string: %s',
                    $index,
                    $evidenceIndex,
                    $path,
                ));
            }
            $typedEvidence[] = $item;
        }

        return new BlindSpotFinding(
            id: $id,
            severity: $parsedSeverity,
            message: $message,
            evidence: $typedEvidence,
        );
    }
}
