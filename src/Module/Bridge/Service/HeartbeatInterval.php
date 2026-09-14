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

    public function __construct(
        private FeatureFlagService $featureFlags,

        #[Autowire(param: 'app.bridge.heartbeat_interval_seconds')]
        private int $default,
    ) {
    }

    public function seconds(): int
    {
        $seconds = $this->featureFlags->getIntValue(self::FLAG, $this->default);

        // The flag is operator-typed, and a bridge given zero would post without pause.
        return $seconds >= 1 ? $seconds : $this->default;
    }
}
