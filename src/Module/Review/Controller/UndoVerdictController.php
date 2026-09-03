<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/**
 * Undo is fieldless, so it stays a plain HTML form guarded by the stateless
 * #[CsrfToken] attribute rather than a Symfony form. It shares the verdict's own
 * token id: both live on the review page and belong to the same action.
 */
#[CsrfToken('submit-review')]
#[IsGranted(DocumentVoter::CONTRIBUTE, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/undo',
    name: 'app_document_review_undo',
    methods: ['POST'],
)]
final class UndoVerdictController extends AppController
{
    public function __construct(
        private readonly UndoVerdictHandler $undoVerdict,
        private readonly TranslatorInterface $translator,
        private readonly Auditor $auditor,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        $routeParameters = [
            'projectId' => (string) $project->id,
            'documentId' => (string) $document->id,
        ];

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        try {
            ($this->undoVerdict)(new UndoVerdictCommand(document: $document, actor: $user));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $this->redirectToRoute('app_document_review', $routeParameters);
        }

        $this->addFlash('success', $this->translator->trans('review.document.flash.verdict_undone'));

        $this->auditor->record(
            'review.document_verdict_undone',
            AuditOutcome::Success,
            [
                'documentId' => (string) $document->id,
                'status' => $document->status->value,
            ],
            new AuditSubject('document', (string) $document->id),
        );

        return $this->redirectToRoute('app_document_review', $routeParameters);
    }
}
