<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tools;

require_once __DIR__ . '/../vendor/autoload.php';

use voku\AgentRecallCompiler\Cli;

final class RecallPruningBenchmark
{
    private string $tempDir;
    private string $learningRoot;
    private string $projectRoot;

    public function __construct()
    {
        $this->tempDir = sys_get_temp_dir() . '/recall-benchmark-' . bin2hex(random_bytes(6));
        $this->projectRoot = $this->tempDir . '/repo';
        $this->learningRoot = $this->projectRoot . '/infra/doc/agent-learning';
    }

    public function run(): array
    {
        $this->setupFixture();

        $archetypes = [
            'Archetype A (Surgical - 2 files)' => [
                'id' => 'TASK-SURGICAL-001',
                'description' => 'Fix password validation boundary condition in auth service.',
                'files' => ['src/Auth/PasswordValidator.php', 'tests/Auth/PasswordValidatorTest.php'],
                'tags' => ['auth', 'security'],
                'validation' => ['vendor/bin/phpunit tests/Auth/PasswordValidatorTest.php'],
            ],
            'Archetype B (Cross-layer - 5 files)' => [
                'id' => 'TASK-CROSSLAYER-002',
                'description' => 'Implement profile export action, view, and repository queries.',
                'files' => [
                    'src/User/ProfileExportAction.php',
                    'src/User/ProfileExportView.php',
                    'templates/user/export.tpl',
                    'src/User/ProfileRepository.php',
                    'tests/User/ProfileExportActionTest.php',
                ],
                'tags' => ['user', 'export'],
                'validation' => ['vendor/bin/phpunit tests/User/ProfileExportActionTest.php'],
            ],
            'Archetype C (Refactor - 12 files)' => [
                'id' => 'TASK-REFACTOR-003',
                'description' => 'Migrate legacy raw database calls to prepared query builders across legacy services.',
                'files' => [
                    'src/Legacy/LegacyStore.php',
                    'src/Legacy/LegacyCustomer.php',
                    'src/Legacy/LegacyOrder.php',
                    'src/Legacy/LegacyInvoice.php',
                    'src/Billing/InvoiceService.php',
                    'src/Billing/PaymentService.php',
                    'src/Billing/TaxCalculator.php',
                    'src/Report/MonthlyReport.php',
                    'src/Report/AnnualReport.php',
                    'tests/Legacy/LegacyStoreTest.php',
                    'tests/Billing/InvoiceServiceTest.php',
                    'tests/Report/MonthlyReportTest.php',
                ],
                'tags' => ['database', 'legacy'],
                'validation' => ['vendor/bin/phpunit tests/Billing/InvoiceServiceTest.php'],
            ],
        ];

        $results = [];
        echo "\n================================================================================\n";
        echo " RECALL INTERMEDIATE BUNDLE PRUNING BENCHMARK\n";
        echo "================================================================================\n";

        foreach ($archetypes as $name => $spec) {
            $results[$name] = $this->measureArchetype($name, $spec);
        }

        $this->printSummaryTable($results);
        $this->cleanup();

        return $results;
    }

    private function measureArchetype(string $name, array $spec): array
    {
        $briefPath = $this->projectRoot . '/task-' . $spec['id'] . '.json';
        file_put_contents($briefPath, json_encode([
            'schema_version' => '1.0',
            'task_id' => $spec['id'],
            'goal' => $spec['description'],
            'scope' => $spec['files'],
            'tags' => $spec['tags'],
            'validation' => $spec['validation'],
            'status' => 'approved',
            'revision' => 1,
        ], JSON_THROW_ON_ERROR));

        $outputDir = $this->tempDir . '/out-' . $spec['id'];
        $cli = new Cli();

        $start = microtime(true);
        $exitCode = $cli->run([
            'agent-recall-compiler',
            'compile',
            '--root', $this->learningRoot,
            '--task', $spec['id'],
            '--task-brief', $briefPath,
            '--output-dir', $outputDir,
            '--compilation-id', 'compilation.' . $spec['id'] . '.bm',
        ]);
        $durationMs = (microtime(true) - $start) * 1000;

        if ($exitCode !== 0) {
            throw new \RuntimeException("Compile failed for {$spec['id']} with code {$exitCode}");
        }

        $bundlePath = $outputDir . '/recall.bundle.json';
        $factsPath = $outputDir . '/facts.json';
        $reportPath = $outputDir . '/selection-report.json';
        $systemPath = $outputDir . '/system.md';

        $bundleSize = is_file($bundlePath) ? filesize($bundlePath) : 0;
        $factsSize = is_file($factsPath) ? filesize($factsPath) : 0;
        $reportSize = is_file($reportPath) ? filesize($reportPath) : 0;
        $systemSize = is_file($systemPath) ? filesize($systemPath) : 0;
        $totalArtifactBytes = $bundleSize + $factsSize + $reportSize + $systemSize;

        $bundleData = json_decode((string) file_get_contents($bundlePath), true, 512, JSON_THROW_ON_ERROR);
        $selectedConstraints = count($bundleData['selected_constraints'] ?? []);
        $selectedGuidance = count($bundleData['selected_guidance'] ?? []);
        $evaluatedGuidance = count($bundleData['evaluated_guidance'] ?? []);

        // Verify determinism: compile a second time and compare hashes
        $outputDir2 = $this->tempDir . '/out2-' . $spec['id'];
        $exitCode2 = $cli->run([
            'agent-recall-compiler',
            'compile',
            '--root', $this->learningRoot,
            '--task', $spec['id'],
            '--task-brief', $briefPath,
            '--output-dir', $outputDir2,
            '--compilation-id', 'compilation.' . $spec['id'] . '.bm',
        ]);
        if ($exitCode2 !== 0) {
            throw new \RuntimeException("Determinism check compile failed for {$spec['id']} with code {$exitCode2}");
        }
        $deterministic = (hash_file('sha256', $bundlePath) === hash_file('sha256', $outputDir2 . '/recall.bundle.json'))
            && (hash_file('sha256', $factsPath) === hash_file('sha256', $outputDir2 . '/facts.json'));

        return [
            'name' => $name,
            'duration_ms' => round($durationMs, 2),
            'bundle_bytes' => $bundleSize,
            'facts_bytes' => $factsSize,
            'report_bytes' => $reportSize,
            'system_bytes' => $systemSize,
            'total_bytes' => $totalArtifactBytes,
            'selected_constraints' => $selectedConstraints,
            'selected_guidance' => $selectedGuidance,
            'evaluated_guidance' => $evaluatedGuidance,
            'deterministic' => $deterministic,
        ];
    }

    private function printSummaryTable(array $results): void
    {
        printf(
            "%-36s | %10s | %10s | %10s | %10s | %10s | %10s\n",
            "Archetype", "Latency", "Bundle", "Facts", "Report", "System.md", "Total Size"
        );
        echo str_repeat('-', 112) . "\n";

        foreach ($results as $r) {
            printf(
                "%-36s | %8.1f ms | %8.1f KB | %8.1f KB | %8.1f KB | %8.1f KB | %8.1f KB\n",
                $r['name'],
                $r['duration_ms'],
                $r['bundle_bytes'] / 1024,
                $r['facts_bytes'] / 1024,
                $r['report_bytes'] / 1024,
                $r['system_bytes'] / 1024,
                $r['total_bytes'] / 1024,
            );
            printf(
                "   -> Constraints: %d selected | Guidance: %d selected / %d evaluated | Deterministic: %s\n",
                $r['selected_constraints'],
                $r['selected_guidance'],
                $r['evaluated_guidance'],
                $r['deterministic'] ? 'YES' : 'NO'
            );
        }
        echo str_repeat('=', 112) . "\n";
    }

    private function setupFixture(): void
    {
        mkdir($this->projectRoot, 0777, true);
        mkdir($this->learningRoot . '/findings/validated', 0777, true);
        mkdir($this->learningRoot . '/proposals/approved', 0777, true);
        mkdir($this->learningRoot . '/constraints/active', 0777, true);
        mkdir($this->learningRoot . '/history', 0777, true);

        // Learning config
        file_put_contents($this->learningRoot . '/config.json', json_encode([
            'schema_version' => '1.0',
            'project_root' => '../../..',
        ], JSON_THROW_ON_ERROR));

        // Generate realistic 150 KB MEMORY.md with core rules and domain-specific sections
        $memoryContent = "# Repository Global Memory\n\n";
        $memoryContent .= "## Core Principles & Never List\n";
        $memoryContent .= "1. Never bypass security boundaries.\n2. Never execute unverified dynamic SQL.\n3. Always declare strict types.\n\n";
        $memoryContent .= "## Baseline Architecture\n";
        $memoryContent .= "Layered MVC architecture with strict module separation and Container DI.\n\n";

        $sectionDomains = [
            'auth' => 'Authentication and Access Tokens',
            'user' => 'User Management and Profiles',
            'billing' => 'Billing Calculation and Payments',
            'report' => 'Reporting Engine and Data Exports',
            'email' => 'Email Delivery and Templates',
            'legacy' => 'Legacy Database Query Builders',
            'search' => 'Search Index and Fuzzy Matching',
            'notification' => 'Notification Webhooks and Dispatch',
            'audit' => 'Audit Logging and Compliance Traces',
            'workflow' => 'Task Workflow and State Machines',
            'inventory' => 'Inventory Tracking and Warehousing',
            'shipping' => 'Shipping Carriers and Tracking',
            'tax' => 'Tax Rates and International Exemption',
            'discount' => 'Promotion Codes and Discount Tiers',
            'order' => 'Order Processing and Fulfillment',
            'queue' => 'Asynchronous Job Queue Workers',
            'cache' => 'Redis Cache Invalidation Policies',
            'storage' => 'S3 Object Storage and Uploads',
            'metrics' => 'Prometheus Metrics and Telemetry',
            'backup' => 'Database Backup and Retention Policies',
        ];

        foreach ($sectionDomains as $dom => $title) {
            $memoryContent .= "## Domain Section: {$title}\n";
            $memoryContent .= "Detailed documentation and invariant rules for {$dom} subsystem.\n";
            $memoryContent .= str_repeat("Durable precedent and operational rule detail for {$dom} domain handling.\n", 75);
            $memoryContent .= "\n";
        }
        file_put_contents($this->projectRoot . '/MEMORY.md', $memoryContent);

        // Generate 60 active proposals across diverse scopes
        $domains = ['auth', 'user', 'billing', 'report', 'email', 'legacy', 'search', 'notification', 'audit', 'workflow'];
        $counter = 1;
        foreach ($domains as $domain) {
            for ($k = 1; $k <= 6; $k++) {
                $findingId = sprintf('finding.2026-06-%02d.%03d', ($counter % 28) + 1, $counter);
                $proposalId = sprintf('proposal.2026-06-%02d.%03d', ($counter % 28) + 1, $counter);

                file_put_contents($this->learningRoot . "/findings/validated/{$findingId}.json", json_encode([
                    'id' => $findingId,
                    'task_id' => 'TASK-INIT-000',
                    'session' => 'session-1',
                    'created_at' => '2026-06-18T08:00:00+00:00',
                    'created_by' => 'benchmark',
                    'scope' => ["src/{$domain}/", "tests/{$domain}/"],
                    'observation' => "Observed behavior in {$domain} subsystem operation {$k}.",
                    'evidence' => [['type' => 'test_result', 'command' => "vendor/bin/phpunit tests/{$domain}/", 'summary' => 'passed']],
                    'hypothesis' => "Enforcing invariants prevents regressions in {$domain}.",
                    'validated_conclusion' => "Maintain strict validation in {$domain} {$k}.",
                    'confidence' => 'high',
                    'validation_status' => 'validated',
                    'status' => 'validated',
                    'sensitivity' => 'public',
                ], JSON_THROW_ON_ERROR));

                file_put_contents($this->learningRoot . "/proposals/approved/{$proposalId}.json", json_encode([
                    'schema_version' => '1.0',
                    'id' => $proposalId,
                    'created_at' => '2026-06-18T09:00:00+00:00',
                    'action' => 'ADD',
                    'target_type' => 'skill',
                    'target' => "skill.{$domain}-rule-{$k}",
                    'scope' => ["src/{$domain}/", "tests/{$domain}/"],
                    'source_findings' => [$findingId],
                    'tags' => [$domain, 'domain-rule'],
                    'old' => null,
                    'new' => "Invariant rule for {$domain} subsystem operation {$k}.",
                    'reason' => "Prevent regression in {$domain} handling.",
                    'boundary' => 'team-approved',
                    'validation' => ["vendor/bin/phpunit tests/{$domain}/"],
                    'status' => 'approved',
                    'proposed_by' => 'agent-loop',
                    'approved_by' => 'reviewer',
                    'approved_at' => '2026-06-18T10:00:00+00:00',
                ], JSON_THROW_ON_ERROR));
                $counter++;
            }
        }

        // Generate 10 mandatory constraints
        $constraintSpecs = [
            ['id' => 'constraint.phpstan.auth-security', 'scope' => ['src/Auth/'], 'tags' => ['auth', 'security'], 'engine' => 'phpstan', 'rule' => 'auth.noDirectSuperglobal', 'cmd' => 'vendor/bin/phpstan analyse src/Auth/'],
            ['id' => 'constraint.phpstan.user-types', 'scope' => ['src/User/'], 'tags' => ['user'], 'engine' => 'phpstan', 'rule' => 'user.strictTypes', 'cmd' => 'vendor/bin/phpstan analyse src/User/'],
            ['id' => 'constraint.phpstan.db-prepared', 'scope' => ['src/Legacy/', 'src/Billing/'], 'tags' => ['database'], 'engine' => 'phpstan', 'rule' => 'db.alwaysBindParameters', 'cmd' => 'vendor/bin/phpstan analyse src/Legacy/ src/Billing/'],
            ['id' => 'constraint.phpstan.report-memory', 'scope' => ['src/Report/'], 'tags' => ['report'], 'engine' => 'phpstan', 'rule' => 'report.streamingMemory', 'cmd' => 'vendor/bin/phpstan analyse src/Report/'],
            ['id' => 'constraint.test.csrf', 'scope' => ['src/User/', 'src/Billing/'], 'tags' => ['security', 'export'], 'engine' => 'test', 'rule' => 'security.csrfValidation', 'cmd' => 'vendor/bin/phpunit tests/Security/CsrfTest.php'],
            ['id' => 'constraint.test.auth-hash', 'scope' => ['src/Auth/'], 'tags' => ['auth'], 'engine' => 'test', 'rule' => 'auth.bcryptWorkFactor', 'cmd' => 'vendor/bin/phpunit tests/Auth/HashTest.php'],
            ['id' => 'constraint.phpstan.billing-decimal', 'scope' => ['src/Billing/'], 'tags' => ['billing', 'database'], 'engine' => 'phpstan', 'rule' => 'billing.exactDecimalMath', 'cmd' => 'vendor/bin/phpstan analyse src/Billing/'],
            ['id' => 'constraint.test.export-sanitization', 'scope' => ['src/User/'], 'tags' => ['export'], 'engine' => 'test', 'rule' => 'export.csvFormulaInjection', 'cmd' => 'vendor/bin/phpunit tests/User/ExportSanitizationTest.php'],
            ['id' => 'constraint.phpstan.legacy-isolation', 'scope' => ['src/Legacy/'], 'tags' => ['legacy'], 'engine' => 'phpstan', 'rule' => 'legacy.noNewStaticAccess', 'cmd' => 'vendor/bin/phpstan analyse src/Legacy/'],
            ['id' => 'constraint.test.audit-log', 'scope' => ['src/Auth/', 'src/User/'], 'tags' => ['security', 'audit'], 'engine' => 'test', 'rule' => 'audit.mandatoryActionLog', 'cmd' => 'vendor/bin/phpunit tests/Audit/AuditLogTest.php'],
        ];

        foreach ($constraintSpecs as $cs) {
            file_put_contents($this->learningRoot . "/constraints/active/{$cs['id']}.json", json_encode([
                'schema_version' => '1.0',
                'id' => $cs['id'],
                'engine' => $cs['engine'],
                'rule_identifier' => $cs['rule'],
                'scope' => $cs['scope'],
                'tags' => $cs['tags'],
                'validation_commands' => [$cs['cmd']],
                'source_proposal' => 'proposal.2026-06-01.001',
                'status' => 'active',
            ], JSON_THROW_ON_ERROR));
        }
    }

    private function cleanup(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $p = $dir . '/' . $file;
            is_dir($p) ? $this->removeDirectory($p) : unlink($p);
        }
        rmdir($dir);
    }
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    (new RecallPruningBenchmark())->run();
}
