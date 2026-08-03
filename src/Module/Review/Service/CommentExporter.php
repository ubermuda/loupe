<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Review\Repository\CommentRepository;

final readonly class CommentExporter implements UserDataExporterInterface
{
    public function __construct(
        private CommentRepository $comments,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'comments.json';
    }

    #[\Override]
    public function export(User $user): array
    {
        $rows = [];
        foreach ($this->comments->findByAuthor($user) as $comment) {
            $rows[] = [
                'id' => (string) $comment->id,
                'parentId' => null !== $comment->parent ? (string) $comment->parent->id : null,
                'document' => $comment->version->document->title,
                'versionNumber' => $comment->version->versionNumber,
                'body' => $comment->body,
                // One flat row per comment, so a reply reports the status of the
                // thread it belongs to rather than dropping the column.
                'status' => $comment->threadStatus->value,
                'orphaned' => $comment->orphaned,
                'anchor' => [
                    'quote' => $comment->anchor->quote,
                    'prefix' => $comment->anchor->prefix,
                    'suffix' => $comment->anchor->suffix,
                    'offsetHint' => $comment->anchor->offsetHint,
                ],
            ];
        }

        return $rows;
    }
}
