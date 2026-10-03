<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxProjectSettings;

class UpdateInboxSettingsRequest
{
    public function __construct(
        public bool $documentInReview = true,
        public bool $runBlocked = true,
        public bool $runGaveUp = true,
        public bool $runWaitingForPerson = true,
        public bool $pullRequestReady = true,
        public bool $pullRequestFixStopped = true,
        public bool $cardPaused = true,
    ) {
    }

    public static function fromSettings(InboxProjectSettings $settings): self
    {
        return new self(
            documentInReview: $settings->documentInReview,
            runBlocked: $settings->runBlocked,
            runGaveUp: $settings->runGaveUp,
            runWaitingForPerson: $settings->runWaitingForPerson,
            pullRequestReady: $settings->pullRequestReady,
            pullRequestFixStopped: $settings->pullRequestFixStopped,
            cardPaused: $settings->cardPaused,
        );
    }
}
