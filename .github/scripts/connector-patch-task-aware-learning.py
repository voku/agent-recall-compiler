from pathlib import Path


def replace_once(source: str, old: str, new: str, label: str) -> str:
    count = source.count(old)
    if count != 1:
        raise SystemExit(f"expected exactly one {label} target, got {count}")
    return source.replace(old, new, 1)


path = Path('src/Provider/AgentLearningNoteProjectionSource.php')
source = path.read_text()
source = replace_once(
    source,
    'final readonly class AgentLearningNoteProjectionSource implements LearningNoteProjectionSource',
    'final readonly class AgentLearningNoteProjectionSource implements TaskAwareLearningNoteProjectionSource',
    'adapter interface',
)
old_header = '''    public function forTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): LearningTaskPrecedentProjection {
'''
new_header = '''    public function forTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): LearningTaskPrecedentProjection {
        return $this->forTaskWithContext($learningRoot, $taskId, $projectRoot);
    }

    /**
     * @param list<string> $taskFiles
     * @param list<string> $taskTags
     */
    public function forTaskWithContext(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
        array $taskFiles = [],
        array $taskTags = [],
    ): LearningTaskPrecedentProjection {
'''
source = replace_once(source, old_header, new_header, 'adapter method header')
source = replace_once(
    source,
    '        $raw = $service->precedentsForTask($learningRoot, $taskId, $projectRoot);\n',
    '''        $method = new \\ReflectionMethod($service, 'precedentsForTask');
        $raw = $method->getNumberOfParameters() >= 6
            ? $service->precedentsForTask($learningRoot, $taskId, $projectRoot, 100, $taskFiles, $taskTags)
            : $service->precedentsForTask($learningRoot, $taskId, $projectRoot);
''',
    'owner invocation',
)
path.write_text(source)


path = Path('src/Provider/LearningNoteRecallProvider.php')
source = path.read_text()
source = replace_once(
    source,
    '''        $selection = $this->source->forTask(
            $rootConfig->root,
            $task->id,
            $rootConfig->projectRoot,
        );
''',
    '''        $selection = $this->source instanceof TaskAwareLearningNoteProjectionSource
            ? $this->source->forTaskWithContext(
                $rootConfig->root,
                $task->id,
                $rootConfig->projectRoot,
                $task->files,
                $task->tags,
            )
            : $this->source->forTask(
                $rootConfig->root,
                $task->id,
                $rootConfig->projectRoot,
            );
''',
    'provider owner query',
)
path.write_text(source)


path = Path('composer.json')
source = path.read_text()
source = replace_once(
    source,
    '    "voku/agent-learning": "^0.18.5"',
    '    "voku/agent-learning": "^0.18.7"',
    'Learning dev floor',
)
path.write_text(source)


path = Path('tests/AgentLearningNoteProjectionSourceTest.php')
tests = path.read_text()
new_test = r'''    public function testTaskContextIsForwardedToReleasedOwnerAndLegacyOwnerStillWorks(): void
    {
        TaskAwareLearningLineageService::$taskFiles = [];
        TaskAwareLearningLineageService::$taskTags = [];

        $source = new AgentLearningNoteProjectionSource(TaskAwareLearningLineageService::class);
        $selection = $source->forTaskWithContext(
            '/tmp/learning',
            'TASK-123',
            taskFiles: ['src/Auth/Login.php'],
            taskTags: ['SECURITY'],
        );

        self::assertSame('TASK-123', $selection->taskId);
        self::assertSame(['src/Auth/Login.php'], TaskAwareLearningLineageService::$taskFiles);
        self::assertSame(['SECURITY'], TaskAwareLearningLineageService::$taskTags);

        $legacy = (new AgentLearningNoteProjectionSource(ReleasedLearningLineageService::class))->forTaskWithContext(
            '/tmp/learning',
            'TASK-123',
            taskFiles: ['src/Auth/Login.php'],
            taskTags: ['security'],
        );
        self::assertSame('TASK-123', $legacy->taskId);
    }

'''
tests = replace_once(
    tests,
    '    public function testPreservesOwnerReportedPrecedentTruncationSeparatelyFromLineageTruncation(): void\n',
    new_test + '    public function testPreservesOwnerReportedPrecedentTruncationSeparatelyFromLineageTruncation(): void\n',
    'adapter test insertion',
)
service = r'''final class TaskAwareLearningLineageService
{
    /** @var list<string> */
    public static array $taskFiles = [];

    /** @var list<string> */
    public static array $taskTags = [];

    /**
     * @param list<string> $taskFiles
     * @param list<string> $taskTags
     */
    public function precedentsForTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
        int $maximumRelatedIdentities = 100,
        array $taskFiles = [],
        array $taskTags = [],
    ): LearningTaskPrecedentResult {
        self::$taskFiles = $taskFiles;
        self::$taskTags = $taskTags;

        return ReleasedLearningTaskPrecedentFixture::create($taskId, str_repeat('a', 64));
    }
}

'''
tests = replace_once(
    tests,
    'final class ReportedTruncationLearningLineageService\n',
    service + 'final class ReportedTruncationLearningLineageService\n',
    'task-aware fake owner insertion',
)
path.write_text(tests)


path = Path('tests/LearningNoteRecallProviderTest.php')
tests = path.read_text()
tests = replace_once(
    tests,
    'use voku\\AgentRecallCompiler\\Provider\\LearningTaskPrecedentProjection;\n',
    'use voku\\AgentRecallCompiler\\Provider\\LearningTaskPrecedentProjection;\nuse voku\\AgentRecallCompiler\\Provider\\TaskAwareLearningNoteProjectionSource;\n',
    'task-aware import',
)
new_test = r'''    public function testTaskBriefContextIsForwardedToTaskAwareOwnerSource(): void
    {
        $source = new class implements TaskAwareLearningNoteProjectionSource {
            /** @var list<string> */
            public array $taskFiles = [];

            /** @var list<string> */
            public array $taskTags = [];

            public function isAvailable(): bool
            {
                return true;
            }

            public function forTask(
                string $learningRoot,
                string $taskId,
                ?string $projectRoot = null,
            ): LearningTaskPrecedentProjection {
                throw new \\RuntimeException('Legacy owner path should not be used for a task-aware source.');
            }

            public function forTaskWithContext(
                string $learningRoot,
                string $taskId,
                ?string $projectRoot = null,
                array $taskFiles = [],
                array $taskTags = [],
            ): LearningTaskPrecedentProjection {
                $this->taskFiles = $taskFiles;
                $this->taskTags = $taskTags;

                return new LearningTaskPrecedentProjection(
                    taskId: $taskId,
                    precedents: [],
                    identityIds: [],
                    depthByIdentityId: [$taskId => 0],
                    relations: [],
                    maximumDepth: 3,
                    maximumResults: 100,
                    truncated: false,
                );
            }
        };

        (new LearningNoteRecallProvider($source))->collect(
            new TaskBrief('TASK-152', 'Bounded precedent task', ['src/Auth/Login.php'], tags: ['SECURITY']),
            new RecallRootConfig('/tmp/learning', 'constraints/active'),
        );

        self::assertSame(['src/Auth/Login.php'], $source->taskFiles);
        self::assertSame(['SECURITY'], $source->taskTags);
    }

'''
tests = replace_once(
    tests,
    '    public function testOwnerFailurePropagatesOutThroughRecall(): void\n',
    new_test + '    public function testOwnerFailurePropagatesOutThroughRecall(): void\n',
    'provider forwarding test insertion',
)
path.write_text(tests)
