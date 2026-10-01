<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Bridge\ValueObject\CliInstallMethod;
use App\Module\Bridge\ValueObject\CliUpdateState;

/** The chip beside a bridge's CLI version. A version outside the range wins over the update state. */
final readonly class AgentUpdateChip
{
    /**
     * @param array<string, string> $parameters
     * @param string|null           $command    the shell command that installs the update, when the bridge does not install it itself
     */
    public function __construct(
        public string $label,
        public array $parameters,
        public string $tone,
        public ?string $command = null,
    ) {
    }

    public static function for(Bridge $bridge, bool $compatible, string $installScriptUrl): ?self
    {
        if (!$compatible) {
            return new self('bridge.agents.update.incompatible', ['%range%' => CliCompatibility::RANGE], 'failed');
        }

        return match ($bridge->updateState) {
            CliUpdateState::Current => new self('bridge.agents.update.current', [], 'ok'),
            CliUpdateState::Updating => new self('bridge.agents.update.updating', [], 'pending'),
            CliUpdateState::RolledBack => new self('bridge.agents.update.rolled_back', ['%version%' => $bridge->updateVersion ?? '?'], 'pending'),
            CliUpdateState::Blocked => new self('bridge.agents.update.blocked', [], 'failed'),
            // The CLI sends a version with "off" only when a newer release in the range waits.
            CliUpdateState::Off => null === $bridge->updateVersion ? null : new self(
                'bridge.agents.update.available',
                ['%version%' => $bridge->updateVersion],
                'neutral',
                CliInstallMethod::Homebrew === $bridge->installMethod
                    ? 'brew upgrade loupe'
                    : 'curl -fsSL '.$installScriptUrl.' | sh',
            ),
            CliUpdateState::Dev, null => null,
        };
    }
}
