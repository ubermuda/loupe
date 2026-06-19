<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Twig\Environment;

/**
 * Builds the Turbo Stream responses for review-sidebar mutations (add comment,
 * reply, resolve), each updating the page in place. When the request does not
 * accept a Turbo Stream (no-JS / non-Turbo), every method falls back to a plain
 * redirect to the review page.
 */
final readonly class ReviewStreamResponder
{
    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
        private CommentRepository $comments,
    ) {
    }

    /**
     * Replace a single thread in place (reply / resolve).
     */
    public function thread(
        Request $request,
        Comment $comment,
        ?string $error = null,
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->stream($request, $comment->version->document, 'review/_comment_thread.stream.html.twig', [
            'comment' => $comment,
            'replies' => $this->comments->findReplies($comment),
            'error' => $error,
        ], $status);
    }

    /**
     * Replace the whole thread list (after a comment is added).
     */
    public function threadList(Request $request, Document $document): Response
    {
        return $this->stream($request, $document, 'review/_comment_added.stream.html.twig', [
            'comments' => $this->comments->findByVersion($document->currentVersion()),
        ], Response::HTTP_OK);
    }

    /**
     * Show a message on the floating composer (add-comment validation failure).
     */
    public function composerError(Request $request, Document $document, string $message): Response
    {
        return $this->stream($request, $document, 'review/_composer_error.stream.html.twig', [
            'message' => $message,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function stream(Request $request, Document $document, string $template, array $context, int $status): Response
    {
        if (!\in_array(TurboBundle::STREAM_MEDIA_TYPE, $request->getAcceptableContentTypes(), true)) {
            return new RedirectResponse($this->urlGenerator->generate(
                'app_document_review',
                ['id' => $document->id],
            ));
        }

        return new Response(
            $this->twig->render($template, $context),
            $status,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
