<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Form\ReplyFormType;
use App\Module\Review\Form\ReplyRequest;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Security\CommentVoter;
use App\Module\Review\Twig\ReviewExtension;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(CommentVoter::REPLY, subject: 'comment')]
#[Route(
    '/comments/{id:comment}/reply',
    name: 'app_comment_reply',
    methods: ['POST'],
)]
final class ReplyToCommentController extends AppController
{
    public function __construct(
        private readonly ReplyToCommentHandler $replyToCommentHandler,
        private readonly CommentRepository $comments,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Comment $comment, Request $request): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $data = new ReplyRequest();
        $form = $this->formFactory->createNamed(ReviewExtension::replyFormName($comment), ReplyFormType::class, $data);
        $form->handleRequest($request);

        $replyForm = null;
        $status = Response::HTTP_OK;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->replyToCommentHandler)(new ReplyToCommentCommand(
                    actor: $user,
                    parent: $comment,
                    body: $data->body ?: throw new \LogicException('body required after validation'),
                ));
            } catch (DomainErrors $e) {
                foreach ($e->errors as $field => $translationKey) {
                    $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
                }
                $replyForm = $form->createView();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        } else {
            $replyForm = $form->createView();
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_document_review', [
                'projectId' => (string) $comment->version->document->project->id,
                'documentId' => (string) $comment->version->document->id,
            ]);
        }

        $html = $this->renderView('@Review/_comment_thread.stream.html.twig', [
            'comment' => $comment,
            'replies' => $this->comments->findReplies($comment),
            'replyForm' => $replyForm,
        ]);

        return new Response($html, $status, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
