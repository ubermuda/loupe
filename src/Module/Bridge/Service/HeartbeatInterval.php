<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * How often a bridge posts its heartbeat, as an admin-editable flag. The server
 * shares the value with each bridge through GET /api/events.
 */
final readonly class HeartbeatInterval
{
    public const string FLAG = 'bridge.heartbeat_interval_seconds';

    /** The CLI applies the same floor, so an older server cannot push a bridge below it. */
    public const int MIN_SECONDS = 10;

    public function __construct(
        private FeatureFlagService $featureFlags,

        #[Autowire(param: 'app.bridge.heartbeat_interval_seconds')]
        private int $default,
    ) {
    }

    public function seconds(): int
    {
        $seconds = $this->featureFlags->getIntValue(self::FLAG, $this->default);

        // The flag is operator-typed. A few seconds would let one bridge spend its token's whole rate limit.
        return $seconds >= self::MIN_SECONDS ? $seconds : $this->default;
    }
}
