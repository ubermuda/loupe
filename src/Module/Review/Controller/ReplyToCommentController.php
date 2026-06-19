<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Form\ReplyFormType;
use App\Module\Review\Form\ReplyRequest;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Twig\ReviewExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/**
 * access is enforced per-branch: denyAccessUnlessGranted() is called imperatively
 * because the subject ($comment->version->document) is derived at runtime from the
 * resolved Comment entity, not directly available as a route parameter, so
 * #[IsGranted(subject:)] cannot be used here.
 */
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
    ) {
    }

    public function __invoke(Comment $comment, Request $request): Response
    {
        $this->denyAccessUnlessGranted(DocumentVoter::VIEW, $comment->version->document);

        $user = $this->getUser();
        assert($user instanceof User);

        $data = new ReplyRequest();
        $form = $this->formFactory->createNamed(ReviewExtension::replyFormName($comment), ReplyFormType::class, $data);
        $form->handleRequest($request);

        $replyForm = null;
        $status = Response::HTTP_OK;

        if ($form->isSubmitted() && $form->isValid()) {
            ($this->replyToCommentHandler)(new ReplyToCommentCommand(
                actor: $user,
                parent: $comment,
                body: $data->body ?: throw new \LogicException('body required after validation'),
            ));
        } else {
            $replyForm = $form->createView();
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_document_review', ['id' => (string) $comment->version->document->id]);
        }

        $html = $this->renderView('review/_comment_thread.stream.html.twig', [
            'comment' => $comment,
            'replies' => $this->comments->findReplies($comment),
            'replyForm' => $replyForm,
        ]);

        return new Response($html, $status, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
