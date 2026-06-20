<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use JsonException;
use RuntimeException;

/**
 * Reads an untrusted peer-feedback file into a {@see FeedbackAssessment}.
 *
 * Accepts either:
 *  - a JSON array of strings (each is a claim),
 *  - a JSON array of objects with `claim`/`text`/`message` and optional `source`,
 *  - a JSON object with an `items` key holding either of the above,
 *  - or plain text, split into claims on blank lines.
 */
final class FeedbackParser
{
    private const string DEFAULT_SOURCE = 'external-agent';

    public function parseFile(string $path): FeedbackAssessment
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Feedback file not found: %s', $path));
        }

        $content = (string) file_get_contents($path);

        return $this->parse($content);
    }

    public function parse(string $content): FeedbackAssessment
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return new FeedbackAssessment([]);
        }

        if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
            $decoded = $this->tryDecodeJson($trimmed);
            if ($decoded !== null) {
                return new FeedbackAssessment($this->itemsFromJson($decoded));
            }
        }

        return new FeedbackAssessment($this->itemsFromText($trimmed));
    }

    private function tryDecodeJson(string $content): mixed
    {
        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @return list<FeedbackItem>
     */
    private function itemsFromJson(mixed $decoded): array
    {
        if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
            $decoded = $decoded['items'];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $entry) {
            if (is_string($entry)) {
                $claim = trim($entry);
                if ($claim !== '') {
                    $items[] = new FeedbackItem(self::DEFAULT_SOURCE, $claim);
                }
                continue;
            }

            if (is_array($entry)) {
                $claim = '';
                foreach (['claim', 'text', 'message'] as $key) {
                    if (isset($entry[$key]) && is_string($entry[$key]) && trim($entry[$key]) !== '') {
                        $claim = trim($entry[$key]);
                        break;
                    }
                }
                if ($claim === '') {
                    continue;
                }
                $source = (isset($entry['source']) && is_string($entry['source']) && trim($entry['source']) !== '')
                    ? trim($entry['source'])
                    : self::DEFAULT_SOURCE;
                $items[] = new FeedbackItem($source, $claim);
            }
        }

        return $items;
    }

    /**
     * @return list<FeedbackItem>
     */
    private function itemsFromText(string $content): array
    {
        $blocks = preg_split('/\R{2,}/', $content) ?: [$content];
        $items = [];
        foreach ($blocks as $block) {
            $claim = trim($block);
            if ($claim !== '') {
                $items[] = new FeedbackItem(self::DEFAULT_SOURCE, $claim);
            }
        }

        return $items;
    }
}
