<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Inbox\Repository\InboxReplyRepository;

final readonly class InboxReplyExporter implements UserDataExporterInterface
{
    public function __construct(
        private InboxReplyRepository $inboxReplies,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'inbox_replies.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->inboxReplies->findByOwner($user) as $reply) {
            yield [
                'id' => (string) $reply->id,
                'itemId' => (string) $reply->item->id,
                'authorId' => (string) $reply->author->id,
                'body' => $reply->body,
                'createdAt' => $reply->createdAt->format(\DATE_ATOM),
            ];
        }
    }
}
