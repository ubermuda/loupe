<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Module\SiteReview\Command\ResolveSiteReviewCommentCommand;
use App\Module\SiteReview\Command\ResolveSiteReviewCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Security\SiteReviewCommentVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
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
    ) {
    }

    public function __invoke(SiteReviewComment $comment): Response
    {
        ($this->handler)(new ResolveSiteReviewCommentCommand($comment));

        return $this->redirectToRoute('app_project_site_review', ['id' => (string) $comment->project->id]);
    }
}
