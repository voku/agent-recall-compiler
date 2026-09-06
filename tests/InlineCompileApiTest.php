<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\InlineCompileTask;
use voku\AgentRecallCompiler\RecallCompiler;

final class InlineCompileApiTest extends TestCase
{
    private string $root;
    private string $mapPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recall-inline-api-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Mail', 0o775, true);
        mkdir($this->root . '/constraints/active', 0o775, true);

        file_put_contents($this->root . '/src/Mail/DunningMailer.php', <<<'PHP'
<?php

namespace Demo\Mail;

final class DunningMailer
{
    public function sendReminder(int $invoiceId): void
    {
        // Sends one overdue invoice reminder.
    }
}
PHP);

        $this->mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map(), $this->mapPath, 'json');
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testEmbeddedCompilerAcceptsTypedInlineTask(): void
    {
        $output = $this->root . '/recall/INLINE-1';
        $target = 'Demo\\Mail\\DunningMailer::sendReminder';

        $result = (new RecallCompiler())->compile(new CompileRequest(
            learningRoot: $this->root,
            taskBrief: null,
            outputDirectory: $output,
            mapIndex: $this->mapPath,
            mapRoot: $this->root,
            editFocus: ['invoice reminder'],
            compilationId: 'edit.INLINE-1',
            inlineTask: new InlineCompileTask(
                taskId: 'INLINE-1',
                description: 'Adjust the overdue invoice reminder.',
                targets: [$target],
            ),
        ));

        self::assertSame('edit.INLINE-1', $result->compilationId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->bundleSha256);
        self::assertFileExists($result->systemPath());
        self::assertFileExists($result->validationPlanPath());

        $bundle = json_decode((string) file_get_contents($result->bundlePath()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($bundle);
        self::assertSame('INLINE-1', $bundle['task']['id']);
        self::assertContains($target, $bundle['task']['targets']);
    }

    public function testCompileRequestRejectsBothTaskSources(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('taskBrief and inlineTask are mutually exclusive.');

        new CompileRequest(
            learningRoot: $this->root,
            taskBrief: $this->root . '/task.json',
            outputDirectory: $this->root . '/out',
            inlineTask: new InlineCompileTask('INLINE-1', '', ['Demo\\Mail\\DunningMailer::sendReminder']),
        );
    }

    public function testCompileRequestRejectsMissingTaskSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('one of taskBrief or inlineTask is required.');

        new CompileRequest(
            learningRoot: $this->root,
            taskBrief: null,
            outputDirectory: $this->root . '/out',
        );
    }

    public function testInlineTaskRejectsEmptyTargetSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('targets must contain at least one target.');

        new InlineCompileTask('INLINE-1', '', []);
    }

    private function map(): AgentMapIndex
    {
        $symbol = new SymbolEntry(
            kind: 'class',
            name: 'DunningMailer',
            fqn: 'Demo\\Mail\\DunningMailer',
            lineStart: 5,
            lineEnd: 11,
            methods: [
                new MethodEntry(
                    'sendReminder',
                    'public',
                    7,
                    10,
                    nativeReturnType: 'void',
                    resolvedReturnType: 'void',
                    reconciliationStatus: 'confirmed',
                ),
            ],
            reconciliationStatus: 'confirmed',
        );

        $hash = hash_file('sha256', $this->root . '/src/Mail/DunningMailer.php');
        self::assertIsString($hash);

        return new AgentMapIndex(
            schemaVersion: '2.0',
            root: '/container/project',
            backend: 'phpstan+simple-parser',
            files: [
                new FileEntry(
                    'src/Mail/DunningMailer.php',
                    'sha256:' . $hash,
                    'Demo\\Mail',
                    [$symbol],
                    'analysed',
                ),
            ],
            relations: [],
            fingerprint: new AnalysisFingerprint('2.2.0', 'sha256:config', 'sha256:lock', 'sha256:sources'),
        );
    }
}
