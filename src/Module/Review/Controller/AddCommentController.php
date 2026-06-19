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
use App\Module\Review\Service\ReviewStreamResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private readonly ReviewStreamResponder $streamResponder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Document $document, Request $request): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $rawStart = $request->request->get('start');
        $rawLength = $request->request->get('length');
        $rawBody = $request->request->get('body');

        if (!is_numeric($rawStart) || (int) $rawStart < 0
            || !is_numeric($rawLength) || (int) $rawLength <= 0
            || !is_string($rawBody) || '' === trim($rawBody)
        ) {
            return $this->streamResponder->composerError(
                $request,
                $document,
                $this->translator->trans('review.document.comment.add_failed'),
            );
        }

        try {
            ($this->addCommentHandler)(new AddCommentCommand(
                actor: $user,
                document: $document,
                start: (int) $rawStart,
                length: (int) $rawLength,
                body: $rawBody,
            ));
        } catch (DomainErrors $e) {
            $message = implode(', ', array_map(
                fn (string $key): string => $this->translator->trans($key),
                $e->errors,
            ));

            return $this->streamResponder->composerError($request, $document, $message);
        }

        return $this->streamResponder->threadList($request, $document);
    }
}
