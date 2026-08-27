<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Review\Command\ListVersionCommentsCommand;
use App\Module\Review\Command\ListVersionCommentsHandler;
use App\Module\Review\Command\ReopenCommentCommand;
use App\Module\Review\Command\ReopenCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Security\CommentVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/**
 * The inverse of ResolveCommentController, and the same shape: a fieldless action
 * guarded by the stateless #[CsrfToken] attribute rather than a Symfony form.
 */
#[CsrfToken('comment-action')]
#[IsGranted(CommentVoter::REOPEN, subject: 'comment')]
#[Route(
    '/comments/{id:comment}/reopen',
    name: 'app_comment_reopen',
    methods: ['POST'],
)]
final class ReopenCommentController extends AppController
{
    public function __construct(
        private readonly ReopenCommentHandler $reopenComment,
        private readonly ListVersionCommentsHandler $listVersionComments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Comment $comment, Request $request): Response
    {
        $version = $comment->version;
        $reviewRoute = [
            'projectId' => (string) $version->document->project->id,
            'documentId' => (string) $version->document->id,
        ];

        try {
            ($this->reopenComment)(new ReopenCommentCommand(comment: $comment));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            // Redirect rather than stream: the flash lives outside #comment-threads,
            // so a stream replacing that region alone would swallow the message.
            return $this->redirectToRoute('app_document_review', $reviewRoute);
        }

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_document_review', $reviewRoute);
        }

        // Re-render the whole thread list: reopening moves the card back from the
        // RESOLVED group to PENDING and shifts the resolved count + progress bar,
        // none of which a single-thread replace would refresh.
        $html = $this->renderView('@Review/_comment_added.stream.html.twig', [
            'comments' => ($this->listVersionComments)(new ListVersionCommentsCommand($version))->comments,
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
