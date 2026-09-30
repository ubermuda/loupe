<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * How long a bridge waits after SIGINT before SIGTERM, and after SIGTERM before
 * SIGKILL, as admin-editable flags. GET /api/events shares both with each bridge.
 */
final readonly class StopLadder
{
    public const string SIGTERM_FLAG = 'bridge.stop_sigterm_after_ms';
    public const string SIGKILL_FLAG = 'bridge.stop_sigkill_after_ms';

    public const int MIN_MS = 100;

    public function __construct(
        private FeatureFlagService $featureFlags,

        #[Autowire(param: 'app.bridge.default_stop_sigterm_after_ms')]
        private int $defaultSigtermAfterMs,

        #[Autowire(param: 'app.bridge.default_stop_sigkill_after_ms')]
        private int $defaultSigkillAfterMs,
    ) {
    }

    public function sigtermAfterMs(): int
    {
        return $this->read(self::SIGTERM_FLAG, $this->defaultSigtermAfterMs);
    }

    public function sigkillAfterMs(): int
    {
        return $this->read(self::SIGKILL_FLAG, $this->defaultSigkillAfterMs);
    }

    private function read(string $flag, int $default): int
    {
        $ms = $this->featureFlags->getIntValue($flag, $default);

        // The flag is operator-typed. A zero delay would skip a step and kill the worker before it can save its state.
        return $ms >= self::MIN_MS ? $ms : $default;
    }
}
