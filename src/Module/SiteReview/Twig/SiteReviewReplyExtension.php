<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Twig;

use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Form\ReplyToSiteReviewCommentFormType;
use App\Module\SiteReview\Form\ReplyToSiteReviewCommentRequest;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Uid\Uuid;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SiteReviewReplyExtension extends AbstractExtension
{
    public function __construct(
        private readonly FormFactoryInterface $forms,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('site_review_reply_form', $this->replyForm(...))];
    }

    public function replyForm(SiteReviewComment $comment, string $surface, ?FormView $refused = null): FormView
    {
        $name = ReplyToSiteReviewCommentFormType::nameFor($comment, $surface);
        if (null !== $refused && $refused->vars['name'] === $name) {
            return $refused;
        }

        return $this->forms->createNamed($name, ReplyToSiteReviewCommentFormType::class,
            new ReplyToSiteReviewCommentRequest(submissionId: (string) Uuid::v4()),
        )->createView();
    }
}
