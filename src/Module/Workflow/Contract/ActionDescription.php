<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** How the settings page and the card panel word an action. The caller translates the keys. */
final readonly class ActionDescription
{
    /**
     * @param string                $settingsKey    the translation key of the action on the settings page
     * @param string                $panelKey       the translation key of the next action on the card panel
     * @param array<string, string> $panelParams    each placeholder of the panel text, with its value
     * @param array<string, string> $panelSlots     each placeholder of the panel text, with the key of the slot whose label fills it
     * @param ?string               $settingsTarget the key of the slot the action targets, or null
     * @param ?string               $settingsDetail the detail the settings page shows next to the action, or null
     */
    public function __construct(
        public string $settingsKey,
        public string $panelKey,
        public array $panelParams = [],
        public array $panelSlots = [],
        public ?string $settingsTarget = null,
        public ?string $settingsDetail = null,
    ) {
    }
}
