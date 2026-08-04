<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

final readonly class VerificationKey
{
    /**
     * @param array<string, ProbeAnswer> $probes
     * @param array{name: string, version: string, seed_sha256: string} $generator
     */
    public function __construct(
        public string $planSha256,
        public string $target,
        public string $mapDigest,
        public array $probes,
        public array $generator,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        /** @var array<string, array<string, mixed>> $probes */
        $probes = [];
        foreach ($this->probes as $id => $answer) {
            $probes[$id] = $answer->toArray();
        }
        ksort($probes, SORT_STRING);

        return [
            'schema_version' => '1.0',
            'plan_sha256' => $this->planSha256,
            'target' => $this->target,
            'map_digest' => $this->mapDigest,
            'generator' => $this->generator,
            'probes' => $probes,
        ];
    }
}
