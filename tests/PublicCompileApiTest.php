<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\RecallCompiler;

final class PublicCompileApiTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recall-public-api-' . bin2hex(random_bytes(6));
        foreach (['proposals/approved', 'proposals/applied', 'proposals/rejected', 'constraints/active', 'history'] as $path) {
            mkdir($this->root . '/learning/' . $path, 0o775, true);
        }
        file_put_contents($this->root . '/task.json', json_encode([
            'schema_version' => '1.0',
            'id' => 'PUBLIC-API-1',
            'description' => 'Compile Recall through the typed PHP owner API.',
            'files' => ['README.md'],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testEmbeddedCompilerReturnsTypedOwnerResult(): void
    {
        $result = (new RecallCompiler())->compile(new CompileRequest(
            learningRoot: $this->root . '/learning',
            taskBrief: $this->root . '/task.json',
            outputDirectory: $this->root . '/recall/PUBLIC-API-1',
            compilationId: 'compilation.PUBLIC-API-1.test',
        ));

        self::assertSame('compilation.PUBLIC-API-1.test', $result->compilationId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->bundleSha256);
        self::assertFileExists($result->systemPath());
        self::assertFileExists($result->validationPlanPath());
        self::assertFileExists($result->factsPath());
        self::assertFileExists($result->metaPath());
        self::assertFileExists($result->bundlePath());
    }

    public function testEmbeddedCompilerDoesNotWriteCliReportToStdout(): void
    {
        $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
        $scriptPath = $this->root . '/embedded.php';
        $script = <<<'PHP'
<?php

declare(strict_types=1);

require __AUTOLOAD__;

(new \voku\AgentRecallCompiler\RecallCompiler())->compile(
    new \voku\AgentRecallCompiler\CompileRequest(
        learningRoot: __LEARNING_ROOT__,
        taskBrief: __TASK_BRIEF__,
        outputDirectory: __OUTPUT_DIRECTORY__,
        compilationId: 'compilation.PUBLIC-API-1.subprocess',
    ),
);
PHP;
        $script = str_replace(
            ['__AUTOLOAD__', '__LEARNING_ROOT__', '__TASK_BRIEF__', '__OUTPUT_DIRECTORY__'],
            [
                var_export($autoloadPath, true),
                var_export($this->root . '/learning', true),
                var_export($this->root . '/task.json', true),
                var_export($this->root . '/subprocess-output', true),
            ],
            $script,
        );
        file_put_contents($scriptPath, $script);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $scriptPath],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start embedded compiler regression process.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame('', $stdout);
        self::assertSame('', $stderr);
        self::assertSame(0, $exitCode);
    }

    public function testRequestRejectsRankedSearchWithoutMapIndex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mapSearchIndex requires mapIndex.');

        new CompileRequest(
            learningRoot: $this->root . '/learning',
            taskBrief: $this->root . '/task.json',
            outputDirectory: $this->root . '/recall/PUBLIC-API-1',
            mapSearchIndex: $this->root . '/search.sqlite',
        );
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
