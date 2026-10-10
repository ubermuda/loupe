<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardAutomationSettings;
use Symfony\Component\Validator\Constraints as Assert;

class SaveBoardAutomationSettingsRequest
{
    public function __construct(
        public bool $enabled = true,

        #[Assert\NotNull]
        #[Assert\Range(min: BoardAutomationSettings::MIN_STUCK_DELAY_MINUTES, max: BoardAutomationSettings::MAX_STUCK_DELAY_MINUTES)]
        public ?int $stuckDelayMinutes = BoardAutomationSettings::DEFAULT_STUCK_DELAY_MINUTES,
    ) {
    }

    public static function fromSettings(BoardAutomationSettings $settings): self
    {
        return new self($settings->enabled, $settings->stuckDelayMinutes);
    }
}
