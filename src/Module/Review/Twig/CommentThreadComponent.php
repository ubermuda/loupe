<?php

declare(strict_types=1);

namespace App\Module\Review\Twig;

use App\Module\Review\Entity\Comment;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders a root comment and any inline replies.
 *
 * Props:
 *   comment  Comment               The root comment (parent === null).
 *   replies  list<Comment>         Direct replies to this comment.
 */
#[AsTwigComponent(name: 'CommentThread', template: 'components/CommentThread.html.twig')]
final class CommentThreadComponent
{
    public Comment $comment;

    /** @var list<Comment> */
    public array $replies = [];
}
