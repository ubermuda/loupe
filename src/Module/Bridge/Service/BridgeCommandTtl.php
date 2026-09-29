<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** How long a command waits for its bridge before it expires, as an admin-editable flag. */
final readonly class BridgeCommandTtl
{
    public const string FLAG = 'bridge.command_ttl_minutes';

    public function __construct(
        private FeatureFlagService $featureFlags,

        #[Autowire(param: 'app.bridge.default_command_ttl_minutes')]
        private int $default,
    ) {
    }

    public function minutes(): int
    {
        $minutes = $this->featureFlags->getIntValue(self::FLAG, $this->default);

        // The flag is operator-typed, and a command that expires at once can never reach a bridge.
        return $minutes >= 1 ? $minutes : $this->default;
    }

    public function expiresAt(\DateTimeImmutable $requestedAt): \DateTimeImmutable
    {
        return $requestedAt->modify('+'.$this->minutes().' minutes');
    }
}
