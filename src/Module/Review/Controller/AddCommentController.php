<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('comment-action')]
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/documents/{id:document}/comments',
    name: 'app_comment_add',
    methods: ['POST'],
)]
final class AddCommentController extends AppController
{
    public function __construct(
        private readonly AddCommentHandler $addCommentHandler,
    ) {
    }

    public function __invoke(Document $document, Request $request): JsonResponse
    {
        $user = $this->getUser();
        assert($user instanceof User);

        try {
            $comment = ($this->addCommentHandler)(new AddCommentCommand(
                actor: $user,
                document: $document,
                start: (int) $request->request->get('start', 0),
                length: (int) $request->request->get('length', 0),
                body: (string) $request->request->get('body', ''),
            ));
        } catch (DomainErrors $e) {
            return $this->json(['errors' => $e->errors], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id' => (string) $comment->id,
            'body' => $comment->body,
            'resolved' => $comment->resolved,
        ], JsonResponse::HTTP_CREATED);
    }
}
