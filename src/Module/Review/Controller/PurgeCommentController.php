<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Review\Command\PurgeCommentCommand;
use App\Module\Review\Command\PurgeCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Form\CommentRecoveryFormType;
use App\Module\Review\Form\CommentRecoveryRequest;
use App\Module\Review\Security\CommentVoter;
use App\Module\Review\Twig\ReviewExtension;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(CommentVoter::PURGE, subject: 'comment')]
#[Route(
    '/comments/{id:comment}/purge',
    name: 'app_comment_purge',
    methods: ['POST'],
)]
final class PurgeCommentController extends AppController
{
    public function __construct(
        private readonly PurgeCommentHandler $purgeComment,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Comment $comment, Request $request): Response
    {
        $commentId = (string) $comment->id;
        $parameters = [
            'projectId' => (string) $comment->version->document->project->id,
            'documentId' => (string) $comment->version->document->id,
        ];
        $data = new CommentRecoveryRequest();
        $form = $this->formFactory->createNamed(
            ReviewExtension::recoveryFormName($comment, 'purge'),
            CommentRecoveryFormType::class,
            $data,
            ['action' => $this->generateUrl('app_comment_purge', ['id' => $commentId]), 'method' => 'POST'],
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $data->deletionSequence) {
            try {
                ($this->purgeComment)(new PurgeCommentCommand($comment, $data->deletionSequence));
                $this->addFlash('success', $this->translator->trans('review.deleted_threads.flash.purged'));

                return $this->redirectToRoute('app_document_deleted_threads', $parameters);
            } catch (DomainErrors $e) {
                foreach ($e->errors as $field => $translationKey) {
                    $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
                }
            }
        }

        return $this->forward(ListDeletedCommentsController::class, [
            ...$parameters,
            'recoveryForm' => $form->createView(),
            'failedCommentId' => $commentId,
            'failedAction' => 'purge',
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
