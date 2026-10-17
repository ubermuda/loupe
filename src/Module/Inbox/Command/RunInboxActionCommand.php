<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxItem;

final readonly class RunInboxActionCommand
{
    public function __construct(
        public InboxItem $item,
        public InboxActionKind $kind,
        public string $note,
        public User $user,
        /** The branch the owner confirmed for a run-command action. Null keeps the one of the suggestion. */
        public ?string $branch = null,
        /**
         * The parameter values the owner confirmed for a run-command action. Null keeps those of the suggestion.
         *
         * @var array<string, string>|null
         */
        public ?array $params = null,
        /** The digest of the run list the owner read in the confirm dialog. */
        public ?string $runDigest = null,
        /** The prompt the owner confirmed for a start-session action. Null or blank starts the session with no prompt. */
        public ?string $sessionPrompt = null,
    ) {
    }
}
