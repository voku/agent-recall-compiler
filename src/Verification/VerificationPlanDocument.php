<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

use InvalidArgumentException;

/**
 * One persisted `verification-plan.json`, bound to the exact bytes read.
 *
 * `sha256` is taken over the file bytes, matching `verification_plan_sha256`
 * in `meta.json` and `plan_sha256` in the verification key, so a consumer can
 * reject answers written against a plan that has since been recompiled.
 *
 * Direct construction is validated as strictly as the reader: a document can
 * only carry the identity and answer-slot shape that a written plan carries.
 */
final readonly class VerificationPlanDocument
{
    /** @var non-empty-string */
    public string $path;
    /** @var non-empty-string */
    public string $sha256;
    /** @var non-empty-string */
    public string $taskId;
    /** @var non-empty-string */
    public string $target;
    /** @var list<non-empty-string> */
    public array $probeIds;
    /** @var list<non-empty-string> */
    public array $checklistIds;

    /**
     * @param array<mixed> $probeIds
     * @param array<mixed> $checklistIds
     * @param array<string, mixed> $plan
     */
    public function __construct(
        string $path,
        string $sha256,
        string $taskId,
        string $target,
        array $probeIds,
        array $checklistIds,
        public array $plan,
    ) {
        $this->path = self::nonEmpty($path, 'path');
        $this->taskId = self::nonEmpty($taskId, 'taskId');
        $this->target = self::nonEmpty($target, 'target');
        if (preg_match('/^sha256:[0-9a-f]{64}$/D', $sha256) !== 1) {
            throw new InvalidArgumentException('VerificationPlanDocument sha256 must be sha256:<64 lowercase hex>.');
        }
        $this->sha256 = $sha256;
        $this->probeIds = self::ids($probeIds, 'probeIds');
        $this->checklistIds = self::ids($checklistIds, 'checklistIds');
    }

    /** @return non-empty-string */
    private static function nonEmpty(string $value, string $field): string
    {
        if ($value === '') {
            throw new InvalidArgumentException('VerificationPlanDocument ' . $field . ' must be a non-empty string.');
        }

        return $value;
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<non-empty-string>
     */
    private static function ids(array $ids, string $field): array
    {
        if (!array_is_list($ids)) {
            throw new InvalidArgumentException('VerificationPlanDocument ' . $field . ' must be a list.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                throw new InvalidArgumentException('VerificationPlanDocument ' . $field . ' must contain only non-empty strings.');
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('VerificationPlanDocument ' . $field . ' must not contain duplicate ids.');
        }

        /** @var list<non-empty-string> $ids */
        return $ids;
    }
}
