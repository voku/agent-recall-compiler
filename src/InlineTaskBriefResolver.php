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

        return new TaskBrief(trim($id), $description, $this->nonEmptyStrings($files), $this->nonEmptyStrings($scopes));
    }

    /** @param list<string> $values @return list<string> */
    private function nonEmptyStrings(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}
