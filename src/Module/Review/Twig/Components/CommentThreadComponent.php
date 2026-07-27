<?php

declare(strict_types=1);

namespace App\Module\Review\Twig\Components;

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
 *   readOnly   bool               True when the thread belongs to a version other
 *                                 than the current one, where reply, resolve and
 *                                 delete would act on a version the reviewer is
 *                                 no longer looking at. Defaults to false.
 */
#[AsTwigComponent(name: 'CommentThread')]
final class CommentThreadComponent
{
    public Comment $comment;

    /** @var list<Comment> */
    public array $replies = [];

    public ?FormView $replyForm = null;

    public bool $readOnly = false;
}
