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

    public const int MAX_WEIGHT = 1_000_000;

    public const int MAX_METRICS = 16;

    private const string METRIC_KEY_PATTERN = '/^[a-z][a-z0-9:-]{0,63}$/D';

    public function __construct(
        public mixed $candidate = null,
        public mixed $variants = null,
        public mixed $weights = null,
        public mixed $metrics = null,
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

    /**
     * The weight of each variant, in the order of the variants. Null when the
     * choice is invalid, or the weights are not one int from 1 to MAX_WEIGHT for
     * each variant. A bad list never refuses the pin, so an older bridge that
     * sends none still runs. A list, because PHP turns a key such as "0" into an int.
     *
     * @return non-empty-list<array{name: string, weight: int<1, max>}>|null
     */
    public function weights(): ?array
    {
        $choice = $this->choice();
        $weights = $this->weights;
        if (null === $choice || !\is_array($weights) || !array_is_list($weights) || \count($weights) !== \count($choice['variants'])) {
            return null;
        }

        $named = [];
        foreach ($choice['variants'] as $i => $name) {
            $weight = $weights[$i];
            if (!\is_int($weight) || $weight < 1 || $weight > self::MAX_WEIGHT) {
                return null;
            }
            $named[] = ['name' => $name, 'weight' => $weight];
        }

        return $named;
    }

    /**
     * The metric keys the experiment declares, in their order. Null when they
     * are not a list of 1 to MAX_METRICS unique keys of the right shape. A key
     * the registry does not know stays, so the page can name it.
     *
     * @return non-empty-list<string>|null
     */
    public function metrics(): ?array
    {
        $metrics = $this->metrics;
        if (!\is_array($metrics) || [] === $metrics || !array_is_list($metrics) || \count($metrics) > self::MAX_METRICS) {
            return null;
        }

        $keys = [];
        foreach ($metrics as $key) {
            if (!\is_string($key) || 1 !== preg_match(self::METRIC_KEY_PATTERN, $key) || \in_array($key, $keys, true)) {
                return null;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    public static function isName(string $name): bool
    {
        return 1 === preg_match(WorkerRun::EXPERIMENT_NAME_PATTERN, $name);
    }
}
