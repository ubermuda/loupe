<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Security\CardFeedbackVoter;
use App\Module\Board\Service\BoardAvailability;
use App\Module\SiteReview\Command\ReplyToSiteReviewCommentCommand;
use App\Module\SiteReview\Command\ReplyToSiteReviewCommentHandler;
use App\Module\SiteReview\Form\ReplyToSiteReviewCommentFormType;
use App\Module\SiteReview\Form\ReplyToSiteReviewCommentRequest;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(CardFeedbackVoter::REPLY, subject: 'link')]
#[Route(
    '/board/feedback/{id:link}/{surface}/reply',
    name: 'app_card_feedback_reply',
    requirements: ['surface' => 'conversation|feedback'],
    methods: ['POST'],
)]
final class ReplyToCardFeedbackController extends AppController
{
    public function __construct(
        private readonly BoardAvailability $board,
        private readonly ReplyToSiteReviewCommentHandler $reply,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, CardSiteReviewComment $link, string $surface): Response
    {
        $this->board->requireEnabled();
        $data = new ReplyToSiteReviewCommentRequest();
        $form = $this->forms->createNamed(ReplyToSiteReviewCommentFormType::nameFor($link->comment, $surface), ReplyToSiteReviewCommentFormType::class, $data);
        $form->handleRequest($request);
        $author = $this->getUser();
        if (!$author instanceof User) {
            throw new \LogicException('A card-feedback reply requires an authenticated owner.');
        }
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->reply)(new ReplyToSiteReviewCommentCommand($link->comment, $author, $data->body ?? '', $data->submissionId ?? ''));
                $this->addFlash('success', $this->translator->trans('site_review.reply.added'));

                return $this->redirectToRoute('app_board_card', ['projectId' => (string) $link->card->project->id, 'cardId' => (string) $link->card->id, 'tab' => $surface]);
            } catch (DomainErrors $error) {
                $this->applyDomainErrors($form, $error);
            }
        }

        return $this->forward(ShowCardController::class, [
            'projectId' => (string) $link->card->project->id,
            'cardId' => (string) $link->card->id,
            'card' => $link->card,
            ShowCardController::REFUSED_FEEDBACK_REPLY => $form->createView(),
        ], ['tab' => $surface])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
