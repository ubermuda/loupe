<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Review\Command\ResolveCommentCommand;
use App\Module\Review\Command\ResolveCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Service\ReviewStreamResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/**
 * access is enforced per-branch: denyAccessUnlessGranted() is called imperatively
 * because the subject ($comment->version->document) is derived at runtime from the
 * resolved Comment entity, not directly available as a route parameter, so
 * #[IsGranted(subject:)] cannot be used here.
 */
#[CsrfToken('comment-action')]
#[Route(
    '/comments/{id:comment}/resolve',
    name: 'app_comment_resolve',
    methods: ['POST'],
)]
final class ResolveCommentController extends AppController
{
    public function __construct(
        private readonly ResolveCommentHandler $resolveCommentHandler,
        private readonly ReviewStreamResponder $streamResponder,
    ) {
    }

    public function __invoke(Comment $comment, Request $request): Response
    {
        $this->denyAccessUnlessGranted(DocumentVoter::VIEW, $comment->version->document);

        ($this->resolveCommentHandler)(new ResolveCommentCommand(
            comment: $comment,
        ));

        return $this->streamResponder->thread($request, $comment);
    }
}
