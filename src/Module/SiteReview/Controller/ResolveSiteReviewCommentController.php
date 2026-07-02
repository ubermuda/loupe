<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\SiteReview\Command\ResolveSiteReviewCommentCommand;
use App\Module\SiteReview\Command\ResolveSiteReviewCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Security\SiteReviewCommentVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('site-review-comment-action')]
#[IsGranted(SiteReviewCommentVoter::RESOLVE, subject: 'comment')]
#[Route(
    '/site-review/comments/{id:comment}/resolve',
    name: 'app_site_review_comment_resolve',
    methods: ['POST'],
)]
final class ResolveSiteReviewCommentController extends AppController
{
    public function __construct(
        private readonly ResolveSiteReviewCommentHandler $handler,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(SiteReviewComment $comment): Response
    {
        try {
            ($this->handler)(new ResolveSiteReviewCommentCommand($comment));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_site_review_site', ['id' => (string) $comment->review->project->id]);
    }
}
