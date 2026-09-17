<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxReply;
use Symfony\Component\Validator\Constraints as Assert;

final class ReplyToInboxItemRequest
{
    public function __construct(
        #[Assert\Length(max: InboxReply::MAX_BODY_LENGTH)]
        #[Assert\NotBlank]
        public ?string $body = null,

        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $submissionId = null,
    ) {
    }
}
