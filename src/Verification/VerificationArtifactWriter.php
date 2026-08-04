<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;

/** Serializes public and verifier-owned artifacts without crossing their boundary. */
final readonly class VerificationArtifactWriter
{
    public function renderPlan(CompiledVerificationPlan $compiled): string
    {
        return CanonicalJson::pretty($compiled->plan->toArray());
    }

    public function renderKey(CompiledVerificationPlan $compiled): string
    {
        return CanonicalJson::pretty($compiled->key->toArray());
    }

    public function renderQuestionsMarkdown(CompiledVerificationPlan $compiled): string
    {
        $lines = [
            '## Repository-Knowledge Verification',
            '',
            'Answer these from current repository evidence. The verifier-owned answer key is deliberately not included.',
            '',
        ];
        if ($compiled->plan->knowledgeProbes === []) {
            $lines[] = '*No eligible deterministic map probes were available for this target.*';
            $lines[] = '';
        } else {
            foreach ($compiled->plan->knowledgeProbes as $probe) {
                $lines[] = sprintf('- **%s**: %s', $probe->id, $probe->question);
                $lines[] = sprintf('  - Answer format: `%s`', $probe->answerFormat);
                $lines[] = sprintf('  - Evidence: `%s`', implode('`, `', $probe->evidenceIds));
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function renderValidationMarkdown(CompiledVerificationPlan $compiled): string
    {
        $lines = ['## Declared Verification Contract', ''];
        foreach ($compiled->plan->checklist as $item) {
            $lines[] = sprintf('- [ ] **%s** `%s`: %s', $item->id, $item->verifier, $item->statement);
        }
        $lines[] = '';
        $lines[] = '### Objective gates';
        $lines[] = '';
        foreach ($compiled->plan->objectiveGates as $gate) {
            $lines[] = sprintf('- [ ] `%s` (%s)', $gate->kind, $gate->required ? 'required' : 'optional');
        }
        $lines[] = '';
        $lines[] = 'The consumer applies this fixed rule:';
        $lines[] = '';
        $lines[] = '```text';
        $lines[] = 'all required objective gates pass -> objective_gate = passed';
        $lines[] = 'any required objective gate fails or is missing -> objective_gate = failed';
        $lines[] = 'objective_gate != passed -> gated_evidence_score = 0';
        $lines[] = '```';
        $lines[] = '';

        return implode("\n", $lines);
    }

    public function write(string $outputDirectory, CompiledVerificationPlan $compiled): void
    {
        $this->writeFile($outputDirectory . '/verification-plan.json', $this->renderPlan($compiled));
        $this->writeFile($outputDirectory . '/verification-key.json', $this->renderKey($compiled));
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Unable to write verification artifact: ' . $path);
        }
    }
}
