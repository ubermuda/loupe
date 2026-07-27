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
        $version = $comment->version;

        ($this->resolveCommentHandler)(new ResolveCommentCommand(
            comment: $comment,
        ));

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_document_review', [
                'projectId' => (string) $version->document->project->id,
                'documentId' => (string) $version->document->id,
            ]);
        }

        // Re-render the whole thread list: resolving moves the card from the
        // PENDING group to RESOLVED and shifts the resolved count + progress bar,
        // none of which a single-thread replace would refresh.
        $html = $this->renderView('@Review/_comment_added.stream.html.twig', [
            'comments' => $this->comments->findByVersion($version),
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
