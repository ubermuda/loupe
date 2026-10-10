<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardAutomationSettings;

class SaveBoardAutomationSettingsRequest
{
    public function __construct(
        public bool $enabled = true,
    ) {
    }

    public static function fromSettings(BoardAutomationSettings $settings): self
    {
        return new self($settings->enabled);
    }
}
