<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

/**
 * The single owner of package-shipped resource locations.
 */
final class PackageResources
{
    public const string SKILLS = 'resources/skills';

    public const string CONSUMER_SKILL = 'resources/skills/agent-recall-consumer';

    public const string MAINTAINER_SKILL = 'resources/skills/agent-recall-compiler-maintainer';

    public const string OPERATING_PROMPTS = 'resources/skills/agent-recall-consumer/operating-prompts.json';

    public const string OPERATING_PROMPTS_METADATA = 'resources/skills/agent-recall-consumer/operating-prompts.metadata.json';

    public static function skillsRoot(): string
    {
        return dirname(__DIR__) . '/' . self::SKILLS;
    }

    public static function consumerSkillRoot(): string
    {
        return dirname(__DIR__) . '/' . self::CONSUMER_SKILL;
    }

    /**
     * @return array<string, string> skill-id => absolute directory path
     */
    public static function consumerSkills(): array
    {
        return [
            'agent-recall-consumer' => self::consumerSkillRoot(),
        ];
    }

    /**
     * @return array<string, string> skill-id => absolute directory path
     */
    public static function maintainerSkills(): array
    {
        return [
            'agent-recall-compiler-maintainer' => dirname(__DIR__) . '/' . self::MAINTAINER_SKILL,
        ];
    }

    public static function consumerInstructionFragment(): ?string
    {
        $path = dirname(__DIR__) . '/resources/instructions/consumer.md';

        return is_file($path) ? $path : null;
    }

    public static function consumerOperatingPrompts(): string
    {
        return dirname(__DIR__) . '/' . self::OPERATING_PROMPTS;
    }

    public static function consumerOperatingPromptsMetadata(): string
    {
        return dirname(__DIR__) . '/' . self::OPERATING_PROMPTS_METADATA;
    }
}
