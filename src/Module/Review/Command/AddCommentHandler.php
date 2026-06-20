<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Service\AnchorService;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AddCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private AnchorService $anchorService,
    ) {
    }

    public function __invoke(AddCommentCommand $command): Comment
    {
        if ($command->document->owner !== $command->actor) {
            throw new DomainErrors(['actor' => 'comment.error.not_owner']);
        }

        $version = $command->document->currentVersion();
        $text = $version->plainText();

        $anchor = $this->anchorService->fromSelection($text, $command->quote, $command->prefix, $command->suffix);

        $comment = new Comment(
            version: $version,
            author: $command->actor,
            body: $command->body,
            anchor: $anchor,
        );

        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }
}
