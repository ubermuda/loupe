<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Review\Command\ResolveCommentCommand;
use App\Module\Review\Command\ResolveCommentHandler;
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
 * Resolve is a fieldless action, so it stays a plain HTML form guarded by the
 * stateless #[CsrfToken] attribute rather than a Symfony form.
 */
#[CsrfToken('comment-action')]
#[IsGranted(CommentVoter::RESOLVE, subject: 'comment')]
#[Route(
    '/comments/{id:comment}/resolve',
    name: 'app_comment_resolve',
    methods: ['POST'],
)]
final class ResolveCommentController extends AppController
{
    public function __construct(
        private readonly ResolveCommentHandler $resolveCommentHandler,
        private readonly CommentRepository $comments,
    ) {
    }

    public function __invoke(Comment $comment, Request $request): Response
    {
        ($this->resolveCommentHandler)(new ResolveCommentCommand(
            comment: $comment,
        ));

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_document_review', [
                'projectId' => (string) $comment->version->document->project->id,
                'documentId' => (string) $comment->version->document->id,
            ]);
        }

        $html = $this->renderView('review/_comment_thread.stream.html.twig', [
            'comment' => $comment,
            'replies' => $this->comments->findReplies($comment),
            'replyForm' => null,
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
