<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use Doctrine\ORM\EntityManagerInterface;

final readonly class ResolveCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ResolveCommentCommand $command): void
    {
        $command->comment->resolved = true;
        $this->em->flush();
    }
}
