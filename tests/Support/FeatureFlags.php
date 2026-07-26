<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\NullLogger;
use Ubermuda\FeatureFlagsBundle\Dto\ResolvedFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;
use Ubermuda\FeatureFlagsBundle\Reader\InMemoryFeatureFlagReader;

/**
 * Builds a real FeatureFlagService over an in-memory reader. The service is not
 * an interface, so tests construct it rather than stubbing it.
 */
final class FeatureFlags
{
    /** @param array<string, bool|int|string> $flags name => value, typed from the PHP value */
    public static function service(array $flags = []): FeatureFlagService
    {
        $resolved = [];

        foreach ($flags as $name => $value) {
            $resolved[] = new ResolvedFlag($name, match (true) {
                is_bool($value) => FeatureFlagType::Bool,
                is_int($value) => FeatureFlagType::Int,
                default => FeatureFlagType::String,
            }, $value);
        }

        return new FeatureFlagService(new InMemoryFeatureFlagReader($resolved), new NullLogger());
    }
}
