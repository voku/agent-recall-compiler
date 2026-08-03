<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Rendering;

final readonly class EditContextRenderer
{
    /**
     * @param list<array<string, mixed>> $facts
     */
    public function render(array $facts): string
    {
        $editFacts = array_values(array_filter(
            $facts,
            static fn (array $fact): bool => ($fact['type'] ?? null) === 'edit_context',
        ));
        if ($editFacts === []) {
            return '';
        }

        $lines = [
            '## Repository Edit Context',
            '',
            'The following context was selected deterministically from the current `agent-map` index. Change candidates may require adaptation. Dependencies and type definitions are context only and must not be edited without current evidence.',
            '',
        ];

        foreach ($editFacts as $fact) {
            $payload = is_array($fact['payload'] ?? null) ? $fact['payload'] : [];
            $target = is_array($payload['target'] ?? null) ? $payload['target'] : [];
            $resolved = is_string($target['resolved'] ?? null) ? $target['resolved'] : (string) ($target['requested'] ?? 'unknown');
            $lines[] = '### Target: `' . $resolved . '`';
            $lines[] = '';
            $this->appendTargetMetadata($lines, $payload, $target);
            $this->appendSlices($lines, $payload);
            $this->appendBlindSpots($lines, $payload);
            $this->appendOmitted($lines, $payload);
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * @param list<string> $lines
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $target
     */
    private function appendTargetMetadata(array &$lines, array $payload, array $target): void
    {
        $file = $target['file'] ?? null;
        $lineStart = $target['line_start'] ?? null;
        $lineEnd = $target['line_end'] ?? null;
        if (is_string($file) && is_int($lineStart) && is_int($lineEnd)) {
            $lines[] = '- **Primary location**: `' . $file . ':' . $lineStart . '-' . $lineEnd . '`';
        }
        if (is_string($payload['map_digest'] ?? null)) {
            $lines[] = '- **Map digest**: `' . $payload['map_digest'] . '`';
        }
        if (is_int($payload['source_bytes'] ?? null)) {
            $lines[] = '- **Selected source**: ' . $payload['source_bytes'] . ' bytes';
        }
        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     * @param array<string, mixed> $payload
     */
    private function appendSlices(array &$lines, array $payload): void
    {
        $slices = is_array($payload['slices'] ?? null) ? $payload['slices'] : [];
        $titles = [
            'primary' => 'Primary',
            'contract' => 'Contracts',
            'change_candidate' => 'Change Candidates',
            'verification' => 'Verification Context',
            'dependency' => 'Dependencies',
            'type_definition' => 'Type Definitions',
        ];
        $groups = array_fill_keys(array_keys($titles), []);

        foreach ($slices as $slice) {
            if (!is_array($slice)) {
                continue;
            }
            $role = $this->primaryRole($slice['roles'] ?? []);
            if ($role === null) {
                continue;
            }
            $groups[$role][] = $slice;
        }

        foreach ($groups as $role => $group) {
            if ($group === []) {
                continue;
            }
            $lines[] = '#### ' . $titles[$role];
            $lines[] = '';
            if (in_array($role, ['dependency', 'type_definition'], true)) {
                $lines[] = '_Context only. Do not edit merely because it was selected._';
                $lines[] = '';
            }
            foreach ($group as $slice) {
                $this->appendSlice($lines, $slice);
            }
        }
    }

    /**
     * @param list<string> $lines
     * @param array<string, mixed> $slice
     */
    private function appendSlice(array &$lines, array $slice): void
    {
        $path = is_string($slice['path'] ?? null) ? $slice['path'] : 'unknown';
        $start = is_int($slice['line_start'] ?? null) ? $slice['line_start'] : 0;
        $end = is_int($slice['line_end'] ?? null) ? $slice['line_end'] : 0;
        $lines[] = '##### `' . $path . ':' . $start . '-' . $end . '`';
        $lines[] = '';

        $reasons = is_array($slice['reasons'] ?? null) ? $slice['reasons'] : [];
        foreach ($reasons as $reason) {
            if (is_string($reason) && trim($reason) !== '') {
                $lines[] = '- **Reason**: ' . trim($reason);
            }
        }
        $lines[] = '';

        $content = is_string($slice['content'] ?? null) ? rtrim($slice['content']) : '';
        $lines[] = '````php';
        if ($content !== '') {
            $lines[] = $content;
        }
        $lines[] = '````';
        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     * @param array<string, mixed> $payload
     */
    private function appendBlindSpots(array &$lines, array $payload): void
    {
        $blindSpots = is_array($payload['blind_spots'] ?? null) ? $payload['blind_spots'] : [];
        if ($blindSpots === []) {
            return;
        }

        $lines[] = '#### Static-analysis Blind Spots';
        $lines[] = '';
        foreach ($blindSpots as $blindSpot) {
            if (!is_array($blindSpot)) {
                continue;
            }
            $message = is_string($blindSpot['message'] ?? null) ? $blindSpot['message'] : 'Unresolved map evidence.';
            $path = $blindSpot['path'] ?? null;
            $line = $blindSpot['line'] ?? null;
            $location = is_string($path) ? ' at `' . $path . (is_int($line) ? ':' . $line : '') . '`' : '';
            $lines[] = '- ' . $message . $location;
        }
        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     * @param array<string, mixed> $payload
     */
    private function appendOmitted(array &$lines, array $payload): void
    {
        $omitted = is_array($payload['omitted'] ?? null) ? $payload['omitted'] : [];
        if ($omitted === []) {
            return;
        }

        $lines[] = '#### Omitted Context';
        $lines[] = '';
        foreach ($omitted as $item) {
            if (!is_array($item)) {
                continue;
            }
            $symbolId = is_string($item['symbol_id'] ?? null) ? $item['symbol_id'] : 'unknown';
            $reason = is_string($item['reason'] ?? null) ? $item['reason'] : 'not selected';
            $lines[] = '- `' . $symbolId . '`: ' . $reason;
        }
        $lines[] = '';
    }

    private function primaryRole(mixed $roles): ?string
    {
        if (!is_array($roles)) {
            return null;
        }
        foreach (['primary', 'contract', 'change_candidate', 'verification', 'dependency', 'type_definition'] as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return null;
    }
}
