<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class InlineTaskBriefResolver
{
    /**
     * @param list<string> $files
     * @param list<string> $scopes
     * @param list<string> $tags
     * @param list<string> $targets
     * @param list<OperatingPromptRequest> $operatingPrompts
     */
    public function resolve(
        string $id,
        string $description = '',
        array $files = [],
        array $scopes = [],
        array $tags = [],
        array $targets = [],
        array $operatingPrompts = [],
    ): TaskBrief {
        if (trim($id) === '') {
            throw new \InvalidArgumentException('inline task input requires a non-empty task id');
        }

        return new TaskBrief(
            id: trim($id),
            description: $description,
            files: $this->nonEmptyStrings($files),
            scopes: $this->nonEmptyStrings($scopes),
            sourcePath: 'inline',
            tags: $this->nonEmptyStrings($tags),
            targets: $this->nonEmptyStrings($targets),
            operatingPrompts: $operatingPrompts,
        );
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
