<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Review\Command\DeleteCommentCommand;
use App\Module\Review\Command\DeleteCommentHandler;
use App\Module\Review\Command\ListVersionCommentsCommand;
use App\Module\Review\Command\ListVersionCommentsHandler;
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
 * Delete is a fieldless action, so it stays a plain HTML form guarded by the
 * stateless #[CsrfToken] attribute.
 */
#[CsrfToken('comment-action')]
#[IsGranted(CommentVoter::DELETE, subject: 'comment')]
#[Route(
    '/comments/{id:comment}/delete',
    name: 'app_comment_delete',
    methods: ['POST'],
)]
final class DeleteCommentController extends AppController
{
    public function __construct(
        private readonly DeleteCommentHandler $deleteCommentHandler,
        private readonly ListVersionCommentsHandler $listVersionComments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Comment $comment, Request $request): Response
    {
        $version = $comment->version;
        $document = $version->document;

        try {
            ($this->deleteCommentHandler)(new DeleteCommentCommand(comment: $comment));
        } catch (DomainErrors $error) {
            foreach ($error->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $this->redirectToRoute('app_document_review', [
                'projectId' => (string) $document->project->id,
                'documentId' => (string) $document->id,
            ]);
        }

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_document_review_version', [
                'projectId' => (string) $document->project->id,
                'documentId' => (string) $document->id,
                'versionNumber' => $version->versionNumber,
            ]);
        }

        $html = $this->renderView('@Review/_comment_deleted.stream.html.twig', [
            'comments' => ($this->listVersionComments)(new ListVersionCommentsCommand($version))->comments,
            'deletedComment' => $comment,
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
