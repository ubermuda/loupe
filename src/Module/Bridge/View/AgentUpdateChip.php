<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Bridge\ValueObject\CliUpdateState;

/** The chip beside a bridge's CLI version. A version outside the range wins over the update state. */
final readonly class AgentUpdateChip
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public string $label,
        public array $parameters,
        public string $tone,
    ) {
    }

    public static function for(Bridge $bridge, bool $compatible): ?self
    {
        if (!$compatible) {
            return new self('bridge.agents.update.incompatible', ['%range%' => CliCompatibility::RANGE], 'failed');
        }

        return match ($bridge->updateState) {
            CliUpdateState::Current => new self('bridge.agents.update.current', [], 'ok'),
            CliUpdateState::Updating => new self('bridge.agents.update.updating', [], 'pending'),
            CliUpdateState::RolledBack => new self('bridge.agents.update.rolled_back', ['%version%' => $bridge->updateVersion ?? '?'], 'pending'),
            CliUpdateState::Blocked => new self('bridge.agents.update.blocked', [], 'failed'),
            CliUpdateState::Off, CliUpdateState::Dev, null => null,
        };
    }
}
