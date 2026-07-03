<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Review\Command\DeleteCommentCommand;
use App\Module\Review\Command\DeleteCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Security\CommentVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/**
 * Delete is a fieldless action, so it stays a plain HTML form guarded by the
 * stateless #[CsrfToken] attribute.
 */
#[CsrfToken('comment-action')]
#[IsGranted(CommentVoter::DELETE, subject: 'comment')]
#[Route(
    '/comments/{id:comment}/delete',
    name: 'app_comment_delete',
    methods: ['POST'],
)]
final class DeleteCommentController extends AppController
{
    public function __construct(
        private readonly DeleteCommentHandler $deleteCommentHandler,
        private readonly CommentRepository $comments,
    ) {
    }

    public function __invoke(Comment $comment, Request $request): Response
    {
        $version = $comment->version;
        $document = $version->document;

        ($this->deleteCommentHandler)(new DeleteCommentCommand(comment: $comment));

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_document_review', [
                'projectId' => (string) $document->project->id,
                'documentId' => (string) $document->id,
            ]);
        }

        // Re-render the whole thread list (restores the empty state when the last
        // comment is gone).
        $html = $this->renderView('review/_comment_added.stream.html.twig', [
            'comments' => $this->comments->findByVersion($version),
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
