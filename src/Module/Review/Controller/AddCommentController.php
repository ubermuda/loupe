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

        $rawStart = $request->request->get('start');
        $rawLength = $request->request->get('length');
        $rawBody = $request->request->get('body');

        $errors = [];
        if (!is_numeric($rawStart) || (int) $rawStart < 0) {
            $errors['start'] = 'start must be a non-negative integer';
        }
        if (!is_numeric($rawLength) || (int) $rawLength <= 0) {
            $errors['length'] = 'length must be a positive integer';
        }
        if (!is_string($rawBody) || '' === trim($rawBody)) {
            $errors['body'] = 'body must not be empty';
        }
        if ([] !== $errors) {
            return $this->json(['errors' => $errors], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $comment = ($this->addCommentHandler)(new AddCommentCommand(
                actor: $user,
                document: $document,
                start: (int) $rawStart,
                length: (int) $rawLength,
                body: (string) $rawBody,
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
