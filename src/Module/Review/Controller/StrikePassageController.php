<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\StrikePassageFormType;
use App\Module\Review\Form\StrikePassageRequest;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Strikes the selected passage: the reviewer's cheapest gesture, so this endpoint
 * takes an anchor and nothing else. It stores a suggestion whose replacement is
 * the empty string, which is what makes the accept path identical to a rewording's.
 */
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/strikes',
    name: 'app_comment_strike',
    methods: ['POST'],
)]
final class StrikePassageController extends AppController
{
    public function __construct(
        private readonly AddCommentHandler $addCommentHandler,
        private readonly DocumentVersionRepository $documentVersions,
        private readonly CommentRepository $comments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        Request $request,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $data = new StrikePassageRequest();
        $form = $this->createForm(StrikePassageFormType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $errorMessage = $this->translator->trans('review.document.strike.failed');
        } else {
            try {
                ($this->addCommentHandler)(new AddCommentCommand(
                    actor: $user,
                    document: $document,
                    quote: $data->quote,
                    prefix: $data->prefix,
                    suffix: $data->suffix,
                    body: '',
                    replacement: '',
                ));
                $errorMessage = null;
            } catch (DomainErrors $e) {
                $errorMessage = implode(' ', array_map(
                    fn (string $key): string => $this->translator->trans($key),
                    $e->errors,
                ));
            }
        }

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_document_review', [
                'projectId' => (string) $project->id,
                'documentId' => (string) $document->id,
            ]);
        }

        if (null !== $errorMessage) {
            // A strike has no composer of its own, so its failures land in the
            // standing error region beside the document instead.
            return new Response(
                $this->renderView('@Review/_composer_error.stream.html.twig', [
                    'target' => 'review-action-error',
                    'message' => $errorMessage,
                ]),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
            );
        }

        return new Response(
            $this->renderView('@Review/_comment_added.stream.html.twig', [
                'comments' => $this->comments->findByVersion($this->documentVersions->findLatest($document)),
            ]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
