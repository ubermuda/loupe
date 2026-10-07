<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Whether bridges sample their host and how often, as admin-editable flags.
 * The server shares both with each bridge through GET /api/events.
 */
final readonly class HostSampling
{
    public const string ENABLED_FLAG = 'bridge.host_sampling_enabled';

    public const string INTERVAL_FLAG = 'bridge.host_sample_interval_seconds';

    public const int MIN_INTERVAL_SECONDS = 5;

    public function __construct(
        private FeatureFlagService $featureFlags,

        #[Autowire(param: 'app.bridge.default_host_sampling_enabled')]
        private bool $defaultEnabled,

        #[Autowire(param: 'app.bridge.default_host_sample_interval_seconds')]
        private int $defaultIntervalSeconds,
    ) {
    }

    public function enabled(): bool
    {
        return $this->featureFlags->isEnabled(self::ENABLED_FLAG, $this->defaultEnabled);
    }

    public function intervalSeconds(): int
    {
        $seconds = $this->featureFlags->getIntValue(self::INTERVAL_FLAG, $this->defaultIntervalSeconds);

        // The flag is operator-typed, and a sample every second would bloat the table.
        return $seconds >= self::MIN_INTERVAL_SECONDS ? $seconds : $this->defaultIntervalSeconds;
    }
}
