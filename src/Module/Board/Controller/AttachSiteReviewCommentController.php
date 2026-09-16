<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\AttachSiteReviewCommentCommand;
use App\Module\Board\Command\AttachSiteReviewCommentHandler;
use App\Module\Board\Command\SearchCardsCommand;
use App\Module\Board\Command\SearchCardsHandler;
use App\Module\Board\Form\AttachSiteReviewCommentFormType;
use App\Module\Board\Form\AttachSiteReviewCommentRequest;
use App\Module\Board\Service\BoardAvailability;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Security\SiteReviewCommentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(SiteReviewCommentVoter::ATTACH, subject: 'comment')]
#[Route(
    '/projects/{projectId}/site-review/{commentId}/attach',
    name: 'app_site_review_comment_attach',
    requirements: ['commentId' => Requirement::UUID],
    methods: ['POST'],
)]
final class AttachSiteReviewCommentController extends AppController
{
    public function __construct(
        private readonly AttachSiteReviewCommentHandler $attach,
        private readonly SearchCardsHandler $searchCards,
        private readonly FormFactoryInterface $formFactory,
        private readonly BoardAvailability $board,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(commentId, projectId)')] SiteReviewComment $comment,
    ): Response {
        $this->board->requireEnabled();

        $data = new AttachSiteReviewCommentRequest();
        $form = $this->formFactory->createNamed(
            AttachSiteReviewCommentFormType::nameFor($comment),
            AttachSiteReviewCommentFormType::class,
            $data,
            ['cards' => ($this->searchCards)(new SearchCardsCommand($comment->project, '', 100))->cards],
        );
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid() || null === $data->card) {
            $this->addFlash('error', $this->translator->trans('site_review.attach.error.invalid'));

            return $this->redirectToRoute('app_project_site_review', [
                'id' => (string) $comment->project->id,
                '_fragment' => 'feedback-'.$comment->id,
            ]);
        }

        try {
            ($this->attach)(new AttachSiteReviewCommentCommand($comment, $data->card));
            $this->addFlash('success', $this->translator->trans('site_review.attach.success', ['%card%' => '#'.$data->card->number]));
        } catch (DomainErrors $e) {
            $this->addFlash('error', $this->translator->trans(array_first($e->errors)));
        }

        return $this->redirectToRoute('app_project_site_review', [
            'id' => (string) $comment->project->id,
            '_fragment' => 'feedback-'.$comment->id,
        ]);
    }
}
