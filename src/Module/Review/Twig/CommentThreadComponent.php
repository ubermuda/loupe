<?php

declare(strict_types=1);

namespace App\Module\Review\Twig;

use App\Module\Review\Entity\Comment;
use Symfony\Component\Form\FormView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders a root comment and any inline replies.
 *
 * Props:
 *   comment    Comment            The root comment (parent === null).
 *   replies    list<Comment>      Direct replies to this comment.
 *   replyForm  FormView|null      A pre-bound reply form to render (e.g. one with
 *                                 validation errors after a failed submit); when
 *                                 null the template builds a fresh one.
 */
#[AsTwigComponent(name: 'CommentThread', template: 'components/CommentThread.html.twig')]
final class CommentThreadComponent
{
    public Comment $comment;

    /** @var list<Comment> */
    public array $replies = [];

    public ?FormView $replyForm = null;
}
