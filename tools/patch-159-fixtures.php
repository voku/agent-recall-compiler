<?php

declare(strict_types=1);

$path = __DIR__ . '/../tests/RecallCompilerTest.php';
$text = file_get_contents($path);
if (!is_string($text)) {
    throw new RuntimeException('Unable to read RecallCompilerTest.php');
}

$targets = [
    <<<'PHP'
        $this->writeProposal('proposal.2026-06-18.001', 'skill', ['src/Auth']);
        $outputDir = $this->root . '/out';
PHP,
    <<<'PHP'
        $this->writeProposal('proposal.2026-06-18.001', 'skill', ['src/Auth']);
        $outputDir = $this->root . '/out-generated';
PHP,
];
foreach ($targets as $target) {
    if (!str_contains($text, $target)) {
        throw new RuntimeException('Targeted compile fixture anchor not found.');
    }
    $replacement = str_replace(
        "\n        \$outputDir",
        "\n        \$this->prepareLearningLineageFixture();\n        \$outputDir",
        $target,
    );
    $text = str_replace($target, $replacement, $text, $count);
    if ($count !== 1) {
        throw new RuntimeException('Unexpected targeted compile fixture anchor count: ' . $count);
    }
}

$genericBlock = <<<'PHP'

        $findingDirectory = $this->root . '/findings/validated';
        if (!is_dir($findingDirectory) && !mkdir($findingDirectory, 0777, true) && !is_dir($findingDirectory)) {
            self::fail('Unable to create Learning finding fixture directory.');
        }
        file_put_contents($findingDirectory . '/finding.2026-06-18.001.json', json_encode([
            'id' => 'finding.2026-06-18.001',
            'task_id' => 'PROJECT-123',
            'session' => 'session_recall_fixture',
            'created_at' => '2026-06-18T09:00:00+00:00',
            'created_by' => 'test',
            'scope' => ['src/Auth'],
            'observation' => 'The Recall fixture carries one approved auth guidance proposal.',
            'evidence' => [[
                'type' => 'file_reference',
                'path' => 'src/Auth/UserService.php',
                'line' => 1,
            ]],
            'hypothesis' => 'The approved proposal should remain eligible for the matching auth task.',
            'validated_conclusion' => 'The fixture represents one validated Learning source for the approved proposal.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        (new LearningLineageService())->rebuild($this->root, $this->root);
PHP;
if (!str_contains($text, $genericBlock)) {
    throw new RuntimeException('Generic Learning fixture block not found.');
}
$text = str_replace($genericBlock, '', $text, $count);
if ($count !== 1) {
    throw new RuntimeException('Unexpected generic fixture block count: ' . $count);
}

$helper = <<<'PHP'
    private function prepareLearningLineageFixture(): void
    {
        $findingDirectory = $this->root . '/findings/validated';
        if (!is_dir($findingDirectory) && !mkdir($findingDirectory, 0777, true) && !is_dir($findingDirectory)) {
            self::fail('Unable to create Learning finding fixture directory.');
        }
        file_put_contents($findingDirectory . '/finding.2026-06-18.001.json', json_encode([
            'id' => 'finding.2026-06-18.001',
            'task_id' => 'PROJECT-123',
            'session' => 'session_recall_fixture',
            'created_at' => '2026-06-18T09:00:00+00:00',
            'created_by' => 'test',
            'scope' => ['src/Auth'],
            'observation' => 'The Recall fixture carries one approved auth guidance proposal.',
            'evidence' => [[
                'type' => 'file_reference',
                'path' => 'src/Auth/UserService.php',
                'line' => 1,
            ]],
            'hypothesis' => 'The approved proposal should remain eligible for the matching auth task.',
            'validated_conclusion' => 'The fixture represents one validated Learning source for the approved proposal.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        (new LearningLineageService())->rebuild($this->root, $this->root);
    }

PHP;
$anchor = '    private function buildEventDraft(string $compilationId): string' . "\n";
if (!str_contains($text, $anchor)) {
    throw new RuntimeException('buildEventDraft anchor not found.');
}
$text = str_replace($anchor, $helper . $anchor, $text, $count);
if ($count !== 1) {
    throw new RuntimeException('Unexpected buildEventDraft anchor count: ' . $count);
}

if (file_put_contents($path, $text) === false) {
    throw new RuntimeException('Unable to write RecallCompilerTest.php');
}
