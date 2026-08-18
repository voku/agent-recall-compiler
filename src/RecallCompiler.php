<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use JsonException;
use RuntimeException;
use voku\AgentRecallCompiler\Command\CompileCommand;

/**
 * Public PHP entrypoint for host packages embedding Recall compilation.
 *
 * Consumers provide typed owner inputs and receive a typed compilation receipt;
 * CLI option spelling and human-oriented stdout remain internal adapters.
 */
final readonly class RecallCompiler
{
    public function compile(CompileRequest $request): CompileResult
    {
        $exitCode = (new CompileCommand(false))->run($this->tokens($request));
        if ($exitCode !== 0) {
            throw new RuntimeException('Recall compilation failed with exit code ' . $exitCode . '.');
        }

        return $this->readResult($request->outputDirectory);
    }

    /** @return list<string> */
    private function tokens(CompileRequest $request): array
    {
        $tokens = [
            '--root', $request->learningRoot,
            '--task-brief', $request->taskBrief,
            '--output-dir', $request->outputDirectory,
            '--map-search-limit', (string) $request->mapSearchLimit,
        ];

        if ($request->compilationId !== null) {
            array_push($tokens, '--compilation-id', $request->compilationId);
        }
        if ($request->feedback !== null) {
            array_push($tokens, '--feedback', $request->feedback);
        }
        if ($request->kanbanContext !== null) {
            array_push($tokens, '--kanban-context', $request->kanbanContext);
        }
        if ($request->mapIndex !== null) {
            array_push($tokens, '--map-index', $request->mapIndex);
        }
        if ($request->mapRoot !== null) {
            array_push($tokens, '--map-root', $request->mapRoot);
        }
        if ($request->mapSearchIndex !== null) {
            array_push($tokens, '--map-search-index', $request->mapSearchIndex);
        }
        foreach ($request->operatingPromptManifests as $manifest) {
            array_push($tokens, '--operating-prompt-manifest', $manifest);
        }
        foreach ($request->documentManifests as $manifest) {
            array_push($tokens, '--document-manifest', $manifest);
        }
        foreach ($request->editFocus as $focus) {
            array_push($tokens, '--edit-focus', $focus);
        }

        return $tokens;
    }

    private function readResult(string $outputDirectory): CompileResult
    {
        $directory = rtrim($outputDirectory, '/\\');
        $path = $directory . '/compilation-receipt.json';
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Recall compilation completed without a readable receipt: ' . $path);
        }

        try {
            $receipt = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Recall compilation receipt is invalid JSON: ' . $path, 0, $exception);
        }
        if (!is_array($receipt) || ($receipt['schema_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Recall compilation receipt has an unsupported schema: ' . $path);
        }

        $compilationId = $receipt['compilation_id'] ?? null;
        $bundleSha256 = $receipt['bundle_sha256'] ?? null;
        if (!is_string($compilationId) || trim($compilationId) === '') {
            throw new RuntimeException('Recall compilation receipt requires a non-empty compilation_id: ' . $path);
        }
        if (!is_string($bundleSha256) || preg_match('/^[a-f0-9]{64}$/', $bundleSha256) !== 1) {
            throw new RuntimeException('Recall compilation receipt requires a canonical bundle_sha256: ' . $path);
        }

        return new CompileResult($directory, $compilationId, $bundleSha256);
    }
}
