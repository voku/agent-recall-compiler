<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class InlineTaskBriefResolver
{
    /**
     * @param list<string> $files
     * @param list<string> $scopes
     */
    public function resolve(string $id, string $description = '', array $files = [], array $scopes = []): TaskBrief
    {
        if (trim($id) === '') {
            throw new \InvalidArgumentException('inline task input requires a non-empty task id');
        }

        $normalizedFiles = $this->nonEmptyStrings($files);
        $normalizedScopes = $this->nonEmptyStrings($scopes);

        return new TaskBrief(trim($id), $description, $normalizedFiles, $normalizedScopes);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function nonEmptyStrings(array $values): array
    {
        /** @var list<string> $normalized */
        $normalized = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($normalized));

        return $unique;
    }
}
