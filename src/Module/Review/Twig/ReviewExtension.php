<?php

declare(strict_types=1);

namespace App\Module\Review\Twig;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Form\ReplyFormType;
use App\Module\Review\Form\ReplyRequest;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Builds the per-thread reply form. A thread renders one reply form each, so the
 * form is created here (per the "form per item in a loop" convention) rather than
 * an array of forms in the controller. Each form is named after its comment so the
 * rendered field ids/names don't collide across threads.
 */
final class ReviewExtension extends AbstractExtension
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('comment_reply_form', $this->commentReplyForm(...)),
        ];
    }

    public function commentReplyForm(Comment $comment): FormView
    {
        return $this->formFactory->createNamed(
            self::replyFormName($comment),
            ReplyFormType::class,
            new ReplyRequest(),
            [
                'action' => $this->urlGenerator->generate('app_comment_reply', ['id' => (string) $comment->id]),
                'method' => 'POST',
            ],
        )->createView();
    }

    /**
     * Stable form name shared by the rendered form (here) and the controller that
     * binds the submission, so handleRequest() reads the right request key.
     */
    public static function replyFormName(Comment $comment): string
    {
        return 'reply_'.$comment->id?->toBase32();
    }
}
