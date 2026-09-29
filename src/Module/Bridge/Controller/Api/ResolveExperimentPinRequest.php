<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\WorkerRun;

/**
 * The variants a rule offers for one experiment, and the one it drew for the
 * card. The fields carry no constraints and accept any JSON value, because a
 * validation failure answers without the error code that the bridge reads.
 * choice() checks them instead.
 */
final class ResolveExperimentPinRequest
{
    public const int MAX_VARIANTS = 32;

    public function __construct(
        public mixed $candidate = null,
        public mixed $variants = null,
    ) {
    }

    /**
     * Null when the variants are not a list of unique names, or the candidate
     * is not one of them.
     *
     * @return array{candidate: string, variants: non-empty-list<string>}|null
     */
    public function choice(): ?array
    {
        $variants = $this->variants;
        if (!\is_array($variants) || [] === $variants || !array_is_list($variants) || \count($variants) > self::MAX_VARIANTS) {
            return null;
        }

        $names = [];
        foreach ($variants as $variant) {
            if (!\is_string($variant) || !self::isName($variant) || \in_array($variant, $names, true)) {
                return null;
            }
            $names[] = $variant;
        }

        if (!\is_string($this->candidate) || !\in_array($this->candidate, $names, true)) {
            return null;
        }

        return ['candidate' => $this->candidate, 'variants' => $names];
    }

    public static function isName(string $name): bool
    {
        return 1 === preg_match(WorkerRun::EXPERIMENT_NAME_PATTERN, $name);
    }
}
