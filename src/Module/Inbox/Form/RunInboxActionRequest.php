<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Inbox\Entity\InboxAction;
use Symfony\Component\Validator\Constraints as Assert;

class RunInboxActionRequest
{
    public const int MAX_PARAMS = 50;

    /**
     * @param array<string, string> $params the parameter values the owner confirmed for a run-command action
     */
    public function __construct(
        #[Assert\Length(max: InboxAction::MAX_NOTE_LENGTH)]
        public ?string $note = null,

        /** The branch the owner confirmed for a run-command action. */
        #[Assert\Length(max: WorkRequest::MAX_BRANCH_LENGTH)]
        public ?string $branch = null,

        #[Assert\Count(max: self::MAX_PARAMS)]
        public array $params = [],

        /** The digest of the run list the confirm dialog showed. */
        #[Assert\Length(max: 64)]
        public ?string $runDigest = null,
        /** The prompt of a start-session action, as the owner left it in the field. The handler checks its length against the flag. */
        public ?string $sessionPrompt = null,
    ) {
    }
}
