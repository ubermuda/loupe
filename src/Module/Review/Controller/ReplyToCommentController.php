<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/**
 * access is enforced per-branch.
 */
#[CsrfToken('comment-action')]
#[Route(
    '/comments/{commentId:comment}/reply',
    name: 'app_comment_reply',
    methods: ['POST'],
)]
final class ReplyToCommentController extends AppController
{
    public function __construct(
        private readonly ReplyToCommentHandler $replyToCommentHandler,
    ) {
    }

    public function __invoke(Comment $comment, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(DocumentVoter::VIEW, $comment->version->document);

        $user = $this->getUser();
        assert($user instanceof User);

        $reply = ($this->replyToCommentHandler)(new ReplyToCommentCommand(
            actor: $user,
            parent: $comment,
            body: (string) $request->request->get('body', ''),
        ));

        return $this->json([
            'id' => (string) $reply->id,
            'body' => $reply->body,
            'resolved' => $reply->resolved,
        ], JsonResponse::HTTP_CREATED);
    }
}
