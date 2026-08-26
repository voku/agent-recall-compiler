<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Public, read-only owner API for operating-prompt discovery, validation and preview.
 *
 * @phpstan-type ArgumentMetadata array{
 *     type: string,
 *     required: bool,
 *     description: string,
 *     minimum: int|null,
 *     maximum: int|null,
 *     examples: list<bool|int|string>
 * }
 * @phpstan-type PromptMetadata array{
 *     title: string,
 *     description: string,
 *     purpose: string,
 *     arguments: array<string, ArgumentMetadata>
 * }
 */
final readonly class OperatingPromptCatalog
{
    /** @var array<string, array{recipe: OperatingPromptRecipe, template: string}> */
    private array $definitions;

    /**
     * @param list<string> $manifestPaths
     */
    public function __construct(array $manifestPaths, ?string $metadataPath = null)
    {
        $paths = [];
        foreach ($manifestPaths as $manifestPath) {
            $manifestPath = trim($manifestPath);
            if ($manifestPath !== '' && !in_array($manifestPath, $paths, true)) {
                $paths[] = $manifestPath;
            }
        }
        sort($paths, SORT_STRING);
        if ($paths === []) {
            throw new InvalidArgumentException('at least one operating prompt manifest is required');
        }

        $metadata = $metadataPath === null ? null : $this->loadMetadata($metadataPath);
        $definitions = [];

        foreach ($paths as $manifestPath) {
            if (!is_file($manifestPath)) {
                throw new RuntimeException('operating prompt manifest not found: ' . $manifestPath);
            }
            $data = $this->decodeObjectFile($manifestPath, 'operating prompt manifest');
            if (($data['schema_version'] ?? null) !== '1.0') {
                throw new RuntimeException('operating prompt manifest must use schema_version "1.0": ' . $manifestPath);
            }
            $prompts = $data['prompts'] ?? null;
            if (!is_array($prompts)) {
                throw new RuntimeException('operating prompt manifest requires a prompts array: ' . $manifestPath);
            }

            foreach ($prompts as $prompt) {
                if (!is_array($prompt)) {
                    throw new RuntimeException('operating prompt manifest entries must be JSON objects: ' . $manifestPath);
                }
                $id = $prompt['id'] ?? null;
                if (!is_string($id) || preg_match('/\A[a-z][a-z0-9._-]*\z/', $id) !== 1) {
                    throw new RuntimeException('operating prompt manifest entry has invalid id: ' . $manifestPath);
                }
                if (isset($definitions[$id])) {
                    throw new RuntimeException('operating prompt id is defined more than once: ' . $id);
                }
                $level = $prompt['level'] ?? null;
                if ($level !== 1 && $level !== 2) {
                    throw new RuntimeException('operating prompt ' . $id . ' requires level 1 or 2');
                }
                $template = $prompt['template'] ?? null;
                if (!is_string($template) || trim($template) === '') {
                    throw new RuntimeException('operating prompt ' . $id . ' requires a non-empty template');
                }
                $template = trim(str_replace(["\r\n", "\r"], "\n", $template));
                $placeholders = $this->placeholderNames($id, $template);
                $recipeMetadata = $metadata[$id] ?? null;
                if ($metadata !== null && $recipeMetadata === null) {
                    throw new RuntimeException('operating prompt metadata missing recipe: ' . $id);
                }

                $recipe = new OperatingPromptRecipe(
                    id: $id,
                    title: $recipeMetadata['title'] ?? $this->fallbackTitle($id),
                    description: $recipeMetadata['description'] ?? 'Operating prompt ' . $id . '.',
                    level: $level,
                    purpose: $recipeMetadata['purpose'] ?? OperatingPromptRecipe::PURPOSE_UNSPECIFIED,
                    arguments: $this->arguments($id, $placeholders, $recipeMetadata['arguments'] ?? null),
                    sourceRef: $manifestPath . '#' . $id,
                    templateSha256: hash('sha256', $template),
                );

                $definitions[$id] = [
                    'recipe' => $recipe,
                    'template' => $template,
                ];
            }
        }

        if ($metadata !== null) {
            $unknownMetadata = array_values(array_diff(array_keys($metadata), array_keys($definitions)));
            sort($unknownMetadata, SORT_STRING);
            if ($unknownMetadata !== []) {
                throw new RuntimeException('operating prompt metadata contains unknown recipes: ' . implode(', ', $unknownMetadata));
            }
        }

        ksort($definitions, SORT_STRING);
        $this->definitions = $definitions;
    }

    public static function bundled(): self
    {
        $root = dirname(__DIR__);

        return new self(
            [$root . '/skills/agent-recall-consumer/operating-prompts.json'],
            $root . '/skills/agent-recall-consumer/operating-prompts.metadata.json',
        );
    }

    /** @return list<OperatingPromptRecipe> */
    public function recipes(): array
    {
        return array_values(array_map(
            static fn (array $definition): OperatingPromptRecipe => $definition['recipe'],
            $this->definitions,
        ));
    }

    public function recipe(string $id): OperatingPromptRecipe
    {
        $definition = $this->definitions[$id] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException('unknown operating prompt id: ' . $id);
        }

        return $definition['recipe'];
    }

    public function validate(OperatingPromptRequest $request): OperatingPromptValidationResult
    {
        $definition = $this->definitions[$request->id] ?? null;
        if ($definition === null) {
            return OperatingPromptValidationResult::invalid(['unknown operating prompt id: ' . $request->id]);
        }

        $recipe = $definition['recipe'];
        $errors = [];
        $known = [];
        foreach ($recipe->arguments as $argument) {
            $known[$argument->name] = true;
            if (!array_key_exists($argument->name, $request->arguments)) {
                if ($argument->required) {
                    $errors[] = 'operating prompt ' . $request->id . ' is missing argument: ' . $argument->name;
                }
                continue;
            }

            $value = $request->arguments[$argument->name];
            if (!$this->valueMatchesType($value, $argument->type)) {
                $errors[] = sprintf(
                    'operating prompt %s argument %s must be %s',
                    $request->id,
                    $argument->name,
                    $argument->type,
                );
                continue;
            }
            if (is_int($value) && $argument->minimum !== null && $value < $argument->minimum) {
                $errors[] = sprintf(
                    'operating prompt %s argument %s must be >= %d',
                    $request->id,
                    $argument->name,
                    $argument->minimum,
                );
            }
            if (is_int($value) && $argument->maximum !== null && $value > $argument->maximum) {
                $errors[] = sprintf(
                    'operating prompt %s argument %s must be <= %d',
                    $request->id,
                    $argument->name,
                    $argument->maximum,
                );
            }
        }

        $extra = [];
        foreach (array_keys($request->arguments) as $name) {
            if (!isset($known[$name])) {
                $extra[] = $name;
            }
        }
        sort($extra, SORT_STRING);
        foreach ($extra as $name) {
            $errors[] = 'operating prompt ' . $request->id . ' received unknown argument: ' . $name;
        }

        return $errors === []
            ? OperatingPromptValidationResult::valid()
            : OperatingPromptValidationResult::invalid($errors);
    }

    public function preview(OperatingPromptRequest $request): OperatingPromptPreview
    {
        $validation = $this->validate($request);
        $definition = $this->definitions[$request->id] ?? null;
        if ($definition === null) {
            return new OperatingPromptPreview($request->id, null, null, null, $validation);
        }
        $recipe = $definition['recipe'];
        if (!$validation->valid) {
            return new OperatingPromptPreview(
                $request->id,
                $recipe->level,
                null,
                $recipe->templateSha256,
                $validation,
            );
        }

        $replacements = [];
        foreach ($request->arguments as $name => $value) {
            $replacements['{{' . $name . '}}'] = $this->argumentValue($value);
        }

        return new OperatingPromptPreview(
            $request->id,
            $recipe->level,
            strtr($definition['template'], $replacements),
            $recipe->templateSha256,
            $validation,
        );
    }

    /**
     * @param list<string> $placeholders
     * @param array<string, ArgumentMetadata>|null $metadata
     * @return list<OperatingPromptArgument>
     */
    private function arguments(string $id, array $placeholders, ?array $metadata): array
    {
        if ($metadata !== null) {
            $extra = array_values(array_diff(array_keys($metadata), $placeholders));
            sort($extra, SORT_STRING);
            if ($extra !== []) {
                throw new RuntimeException('operating prompt metadata contains unknown arguments for ' . $id . ': ' . implode(', ', $extra));
            }
        }

        $arguments = [];
        foreach ($placeholders as $name) {
            $argumentMetadata = $metadata[$name] ?? null;
            if ($metadata !== null && $argumentMetadata === null) {
                throw new RuntimeException('operating prompt metadata missing argument ' . $name . ' for ' . $id);
            }
            $arguments[] = new OperatingPromptArgument(
                name: $name,
                type: $argumentMetadata['type'] ?? OperatingPromptArgument::TYPE_SCALAR,
                required: $argumentMetadata['required'] ?? true,
                description: $argumentMetadata['description'] ?? 'Required template argument.',
                minimum: $argumentMetadata['minimum'] ?? null,
                maximum: $argumentMetadata['maximum'] ?? null,
                examples: $argumentMetadata['examples'] ?? [],
            );
        }

        return $arguments;
    }

    /**
     * @return array<string, PromptMetadata>
     */
    private function loadMetadata(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('operating prompt metadata not found: ' . $path);
        }
        $data = $this->decodeObjectFile($path, 'operating prompt metadata');
        if (($data['schema_version'] ?? null) !== '1.0') {
            throw new RuntimeException('operating prompt metadata must use schema_version "1.0": ' . $path);
        }
        $recipes = $data['recipes'] ?? null;
        if (!is_array($recipes)) {
            throw new RuntimeException('operating prompt metadata requires a recipes array: ' . $path);
        }

        $metadata = [];
        foreach ($recipes as $recipe) {
            if (!is_array($recipe)) {
                throw new RuntimeException('operating prompt metadata recipes must be JSON objects: ' . $path);
            }
            $id = $recipe['id'] ?? null;
            $title = $recipe['title'] ?? null;
            $description = $recipe['description'] ?? null;
            $purpose = $recipe['purpose'] ?? null;
            if (!is_string($id) || preg_match('/\A[a-z][a-z0-9._-]*\z/', $id) !== 1) {
                throw new RuntimeException('operating prompt metadata recipe has invalid id: ' . $path);
            }
            if (isset($metadata[$id])) {
                throw new RuntimeException('operating prompt metadata recipe is defined more than once: ' . $id);
            }
            if (!is_string($title) || trim($title) === '') {
                throw new RuntimeException('operating prompt metadata recipe requires a title: ' . $id);
            }
            if (!is_string($description) || trim($description) === '') {
                throw new RuntimeException('operating prompt metadata recipe requires a description: ' . $id);
            }
            if (!is_string($purpose) || trim($purpose) === '') {
                throw new RuntimeException('operating prompt metadata recipe requires a purpose: ' . $id);
            }

            $rawArguments = $recipe['arguments'] ?? [];
            if (!is_array($rawArguments)) {
                throw new RuntimeException('operating prompt metadata arguments must be an array: ' . $id);
            }
            $arguments = [];
            foreach ($rawArguments as $argument) {
                if (!is_array($argument)) {
                    throw new RuntimeException('operating prompt metadata arguments must be JSON objects: ' . $id);
                }
                $name = $argument['name'] ?? null;
                $type = $argument['type'] ?? null;
                $required = $argument['required'] ?? null;
                $argumentDescription = $argument['description'] ?? null;
                if (!is_string($name) || preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
                    throw new RuntimeException('operating prompt metadata argument has invalid name: ' . $id);
                }
                if (isset($arguments[$name])) {
                    throw new RuntimeException('operating prompt metadata argument is defined more than once: ' . $id . '.' . $name);
                }
                if (!is_string($type)) {
                    throw new RuntimeException('operating prompt metadata argument requires a type: ' . $id . '.' . $name);
                }
                if (!is_bool($required)) {
                    throw new RuntimeException('operating prompt metadata argument requires a boolean required flag: ' . $id . '.' . $name);
                }
                if (!$required) {
                    throw new RuntimeException('template placeholders are currently required arguments: ' . $id . '.' . $name);
                }
                if (!is_string($argumentDescription) || trim($argumentDescription) === '') {
                    throw new RuntimeException('operating prompt metadata argument requires a description: ' . $id . '.' . $name);
                }

                $minimum = $argument['minimum'] ?? null;
                $maximum = $argument['maximum'] ?? null;
                if ($minimum !== null && !is_int($minimum)) {
                    throw new RuntimeException('operating prompt metadata minimum must be an integer: ' . $id . '.' . $name);
                }
                if ($maximum !== null && !is_int($maximum)) {
                    throw new RuntimeException('operating prompt metadata maximum must be an integer: ' . $id . '.' . $name);
                }

                $rawExamples = $argument['examples'] ?? [];
                if (!is_array($rawExamples)) {
                    throw new RuntimeException('operating prompt metadata examples must be an array: ' . $id . '.' . $name);
                }
                $examples = [];
                foreach ($rawExamples as $example) {
                    if (!is_bool($example) && !is_int($example) && !is_string($example)) {
                        throw new RuntimeException('operating prompt metadata examples must be scalar JSON values: ' . $id . '.' . $name);
                    }
                    $examples[] = $example;
                }

                $projection = new OperatingPromptArgument(
                    $name,
                    $type,
                    $required,
                    $argumentDescription,
                    $minimum,
                    $maximum,
                    $examples,
                );
                $arguments[$name] = [
                    'type' => $projection->type,
                    'required' => $projection->required,
                    'description' => $projection->description,
                    'minimum' => $projection->minimum,
                    'maximum' => $projection->maximum,
                    'examples' => $projection->examples,
                ];
            }
            ksort($arguments, SORT_STRING);

            $metadata[$id] = [
                'title' => trim($title),
                'description' => trim($description),
                'purpose' => trim($purpose),
                'arguments' => $arguments,
            ];
        }
        ksort($metadata, SORT_STRING);

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function decodeObjectFile(string $path, string $kind): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('cannot read ' . $kind . ': ' . $path);
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('invalid ' . $kind . ' ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException($kind . ' must be a JSON object: ' . $path);
        }

        return $data;
    }

    /** @return list<string> */
    private function placeholderNames(string $id, string $template): array
    {
        preg_match_all('/\{\{([a-z][a-z0-9_]*)\}\}/', $template, $matches);
        /** @var list<string> $names */
        $names = array_values(array_unique($matches[1]));
        sort($names, SORT_STRING);

        $withoutPlaceholders = preg_replace('/\{\{[a-z][a-z0-9_]*\}\}/', '', $template);
        if (!is_string($withoutPlaceholders) || str_contains($withoutPlaceholders, '{{') || str_contains($withoutPlaceholders, '}}')) {
            throw new RuntimeException('operating prompt has malformed placeholder syntax: ' . $id);
        }

        return $names;
    }

    private function fallbackTitle(string $id): string
    {
        return ucwords(str_replace(['-', '_', '.'], ' ', $id));
    }

    /** @param OperatingPromptArgument::TYPE_* $type */
    private function valueMatchesType(bool|int|string $value, string $type): bool
    {
        return match ($type) {
            OperatingPromptArgument::TYPE_BOOLEAN => is_bool($value),
            OperatingPromptArgument::TYPE_INTEGER => is_int($value),
            OperatingPromptArgument::TYPE_STRING => is_string($value),
            OperatingPromptArgument::TYPE_SCALAR => true,
        };
    }

    private function argumentValue(bool|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
