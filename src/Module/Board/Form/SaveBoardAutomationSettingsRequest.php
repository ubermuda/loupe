<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\BoardFixStrategy;
use App\Module\Board\Entity\BoardMergeStrategy;
use Symfony\Component\Validator\Constraints as Assert;

class SaveBoardAutomationSettingsRequest
{
    public function __construct(
        public bool $enabled = true,

        #[Assert\NotNull]
        public ?BoardMergeStrategy $mergeStrategy = BoardMergeStrategy::Worker,

        #[Assert\NotNull]
        public ?BoardFixStrategy $fixStrategy = BoardFixStrategy::Fresh,

        #[Assert\NotNull]
        #[Assert\Range(min: BoardAutomationSettings::MIN_LOOP_LIMIT, max: BoardAutomationSettings::MAX_LOOP_LIMIT)]
        public ?int $loopLimit = 3,
        public bool $commentOnFixQueued = false,
        public bool $commentOnStaleApproval = false,
        public bool $syncBehind = false,
    ) {
    }

    public static function fromSettings(BoardAutomationSettings $settings): self
    {
        return new self($settings->enabled, $settings->mergeStrategy, $settings->fixStrategy, $settings->loopLimit, $settings->commentOnFixQueued, $settings->commentOnStaleApproval, $settings->syncBehind);
    }
}
