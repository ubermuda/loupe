<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Controller\ShowCardController;
use App\Module\Inbox\Controller\ShowInboxController;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxLinkedPage;
use App\Module\Inbox\View\InboxReturnTarget;
use App\Module\Review\Controller\ShowDocumentController;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentVersionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the page a response form came from out of its query string.
 *
 * Only a card or document the item links to is accepted, and the route is
 * always built here, so the query can never send the owner to an address of its
 * choosing. Anything else returns to the inbox. The check runs before the
 * response is saved, so a saved response never redirects to a missing page.
 */
final readonly class InboxReturnTargetResolver
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    public function resolve(Request $request, InboxItem $item): InboxReturnTarget
    {
        $projectId = (string) $item->project->id;
        $page = InboxLinkedPage::tryFrom($request->query->getString(InboxReturnTarget::PAGE_QUERY));
        $rawId = $request->query->getString(InboxReturnTarget::ID_QUERY);
        $id = Uuid::isValid($rawId) ? Uuid::fromString($rawId) : null;

        if (InboxLinkedPage::Card === $page && null !== $id && $item->cards->exists(static fn (int $key, InboxItemCard $link): bool => (bool) $link->card->id?->equals($id))) {
            $target = ['projectId' => $projectId, 'cardId' => $id->toRfc4122()];

            return new InboxReturnTarget('app_board_card', $target, ShowCardController::class, $target, []);
        }

        $document = InboxLinkedPage::Document === $page && null !== $id ? $this->linkedDocument($item, $id) : null;
        if (null !== $document && null !== $id) {
            $target = ['projectId' => $projectId, 'documentId' => $id->toRfc4122()];
            // An older version is a page of its own, and the owner stays on it. A
            // version the document lacks returns to the current one instead.
            $version = $request->query->getInt(InboxReturnTarget::VERSION_QUERY);
            if ($version >= 1 && 1 === $this->documentVersions->countByNumbers($document, [$version])) {
                $target['versionNumber'] = $version;

                return new InboxReturnTarget('app_document_review_version', $target, ShowDocumentController::class, $target, []);
            }

            return new InboxReturnTarget('app_document_review', $target, ShowDocumentController::class, $target, []);
        }

        // The closed asks page the owner was on, kept on the way back.
        $pageQuery = $request->query->getInt('page', 1) > 1 ? ['page' => $request->query->getInt('page')] : [];

        return new InboxReturnTarget('app_project_inbox', ['id' => $projectId, ...$pageQuery], ShowInboxController::class, ['id' => $projectId, 'project' => $item->project], $pageQuery);
    }

    private function linkedDocument(InboxItem $item, Uuid $id): ?Document
    {
        $link = $item->documents->findFirst(static fn (int $key, InboxItemDocument $link): bool => (bool) $link->document->id?->equals($id));

        return $link?->document;
    }
}
