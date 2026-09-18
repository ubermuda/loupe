<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Command\ReplyToSiteReviewCommentCommand;
use App\Module\SiteReview\Command\ReplyToSiteReviewCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Form\ReplyToSiteReviewCommentFormType;
use App\Module\SiteReview\Form\ReplyToSiteReviewCommentRequest;
use App\Module\SiteReview\Security\SiteReviewCommentVoter;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(SiteReviewCommentVoter::REPLY, subject: 'comment')]
#[Route(
    '/site-review/comments/{id:comment}/reply',
    name: 'app_site_review_comment_reply',
    methods: ['POST'],
)]
final class ReplyToSiteReviewCommentController extends AppController
{
    public function __construct(
        private readonly ReplyToSiteReviewCommentHandler $reply,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, SiteReviewComment $comment): Response
    {
        $data = new ReplyToSiteReviewCommentRequest();
        $form = $this->forms->createNamed(ReplyToSiteReviewCommentFormType::nameFor($comment, 'site'), ReplyToSiteReviewCommentFormType::class, $data);
        $form->handleRequest($request);
        $author = $this->getUser();
        if (!$author instanceof User) {
            throw new \LogicException('A site-feedback reply requires an authenticated owner.');
        }
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->reply)(new ReplyToSiteReviewCommentCommand($comment, $author, $data->body ?? '', $data->submissionId ?? ''));
                $this->addFlash('success', $this->translator->trans('site_review.reply.added'));

                return $this->redirectToRoute('app_project_site_review', ['id' => (string) $comment->project->id, '_fragment' => 'feedback-'.$comment->id]);
            } catch (DomainErrors $error) {
                $this->applyDomainErrors($form, $error);
            }
        }

        return $this->forward(ShowSiteReviewController::class, [
            'id' => (string) $comment->project->id,
            'project' => $comment->project,
            ShowSiteReviewController::REFUSED_REPLY => $form->createView(),
            'selectedFeedback' => 'feedback-'.$comment->id,
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
