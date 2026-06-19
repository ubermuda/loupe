<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Repository\CommentRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Twig\Environment;

/**
 * Builds the response for a reply/resolve mutation: a Turbo Stream that replaces
 * the affected thread in place when the request accepts one, otherwise a plain
 * redirect back to the review page (no-JS / non-Turbo fallback).
 */
final readonly class CommentThreadResponder
{
    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
        private CommentRepository $comments,
    ) {
    }

    public function respond(
        Request $request,
        Comment $comment,
        ?string $error = null,
        int $status = Response::HTTP_OK,
    ): Response {
        if (!\in_array(TurboBundle::STREAM_MEDIA_TYPE, $request->getAcceptableContentTypes(), true)) {
            return new RedirectResponse($this->urlGenerator->generate(
                'app_document_review',
                ['id' => $comment->version->document->id],
            ));
        }

        $html = $this->twig->render('review/_comment_thread.stream.html.twig', [
            'comment' => $comment,
            'replies' => $this->comments->findReplies($comment),
            'error' => $error,
        ]);

        return new Response($html, $status, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
