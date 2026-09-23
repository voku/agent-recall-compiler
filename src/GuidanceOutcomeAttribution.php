<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

/**
 * Decision-time evidence written with a guidance outcome: whether the session
 * read the guidance before the credited decision, and which other sources
 * already prescribed that decision. Learning owns its meaning; Recall only
 * records it.
 */
final readonly class GuidanceOutcomeAttribution
{
    /**
     * @param list<GuidanceOutcomeAttributionSource> $alsoPrescribedBy
     */
    public function __construct(
        public bool $seenBeforeDecision,
        public array $alsoPrescribedBy,
    ) {
    }

    /**
     * @return array{seen_before_decision: bool, also_prescribed_by: list<string>}
     */
    public function toArray(): array
    {
        return [
            'seen_before_decision' => $this->seenBeforeDecision,
            'also_prescribed_by' => array_map(
                static fn (GuidanceOutcomeAttributionSource $source): string => $source->value,
                $this->alsoPrescribedBy,
            ),
        ];
    }
}
