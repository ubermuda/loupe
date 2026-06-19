<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Service\ReviewStreamResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/**
 * access is enforced per-branch: denyAccessUnlessGranted() is called imperatively
 * because the subject ($comment->version->document) is derived at runtime from the
 * resolved Comment entity, not directly available as a route parameter, so
 * #[IsGranted(subject:)] cannot be used here.
 */
#[CsrfToken('comment-action')]
#[Route(
    '/comments/{id:comment}/reply',
    name: 'app_comment_reply',
    methods: ['POST'],
)]
final class ReplyToCommentController extends AppController
{
    public function __construct(
        private readonly ReplyToCommentHandler $replyToCommentHandler,
        private readonly ReviewStreamResponder $streamResponder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Comment $comment, Request $request): Response
    {
        $this->denyAccessUnlessGranted(DocumentVoter::VIEW, $comment->version->document);

        $user = $this->getUser();
        assert($user instanceof User);

        $rawBody = $request->request->get('body');
        if (!is_string($rawBody) || '' === trim($rawBody)) {
            return $this->streamResponder->thread(
                $request,
                $comment,
                $this->translator->trans('review.document.comment.reply_required'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        ($this->replyToCommentHandler)(new ReplyToCommentCommand(
            actor: $user,
            parent: $comment,
            body: $rawBody,
        ));

        return $this->streamResponder->thread($request, $comment);
    }
}
