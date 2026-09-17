<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Security\CardFeedbackVoter;
use App\Module\Board\Service\BoardAvailability;
use App\Module\SiteReview\Command\ReopenSiteReviewCommentCommand;
use App\Module\SiteReview\Command\ReopenSiteReviewCommentHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('site-review-comment-action')]
#[IsGranted(CardFeedbackVoter::REOPEN, subject: 'link')]
#[Route(
    '/board/feedback/{id:link}/{surface}/reopen',
    name: 'app_card_feedback_reopen',
    requirements: ['surface' => 'conversation|feedback'],
    methods: ['POST'],
)]
final class ReopenCardFeedbackController extends AppController
{
    public function __construct(
        private readonly BoardAvailability $board,
        private readonly ReopenSiteReviewCommentHandler $handler,
    ) {
    }

    public function __invoke(CardSiteReviewComment $link, string $surface): Response
    {
        $this->board->requireEnabled();
        ($this->handler)(new ReopenSiteReviewCommentCommand($link->comment));

        return $this->redirectToRoute('app_board_card', [
            'projectId' => (string) $link->card->project->id,
            'cardId' => (string) $link->card->id,
            'tab' => $surface,
        ]);
    }
}
