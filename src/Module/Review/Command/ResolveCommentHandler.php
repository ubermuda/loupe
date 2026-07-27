<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ResolveCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private CommentRepository $comments,
    ) {
    }

    public function __invoke(ResolveCommentCommand $command): void
    {
        // Resolving a thread resolves its replies with it — findOpenByVersion()
        // (used by ReviseDocumentHandler to carry comments into the next
        // version) selects on resolved = false with no parent check, so a
        // reply left unresolved would otherwise be copied onto the new version
        // with its parent detached (the resolved root isn't in the open set),
        // resurfacing as a brand-new unresolved top-level thread on every
        // revision. There is no UI path to resolve a reply independently of
        // its root (see CommentThread.html.twig), so a reply's resolved state
        // only ever needs to track its thread's.
        foreach ($this->comments->findReplies($command->comment) as $reply) {
            $reply->resolved = true;
        }
        $command->comment->resolved = true;
        $this->em->flush();
    }
}
