<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardAutomationSettings;
use Symfony\Component\Validator\Constraints as Assert;

class SaveBoardTerminalWindowRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Range(min: BoardAutomationSettings::MIN_TERMINAL_WINDOW_DAYS, max: BoardAutomationSettings::MAX_TERMINAL_WINDOW_DAYS)]
        public ?int $terminalWindowDays = 3,
    ) {
    }
}
