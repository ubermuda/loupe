<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Controller\ShowCardController;
use App\Module\Inbox\Controller\ShowInboxController;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxLinkedPage;
use App\Module\Inbox\Repository\InboxAskRepository;
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
        private InboxAskRepository $inboxAsks,
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

            return new InboxReturnTarget('app_board_card', [...$target, 'tab' => 'conversation'], ShowCardController::class, $target, ['tab' => 'conversation']);
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

        // The queue, the page and the search the owner was on, kept on the way back.
        $search = trim($request->query->getString('q'));
        // Read as objects, so an ask closed in this request counts as closed.
        $asks = $this->inboxAsks->findHolding($item);
        $closed = InboxItemState::Open !== $item->state;
        $completed = 'completed' === $request->query->getString('queue')
            || ($closed && [] !== $asks && [] === array_filter($asks, static fn (InboxAsk $ask): bool => null === $ask->closedAt));

        $pageQuery = [
            ...('' === $search && $completed ? ['queue' => 'completed'] : []),
            ...($request->query->getInt('page', 1) > 1 ? ['page' => $request->query->getInt('page')] : []),
            ...('' === $search ? [] : ['q' => $search]),
        ];

        // The fragment opens the request on arrival, so it is named only where
        // the page shows it. A closed item that no ask holds is on neither
        // queue, and naming it would send the owner hunting for it instead of
        // back to the work that is left. A forward needs no fragment at all: it
        // re-renders the page the form was already on.
        $shown = '' !== $search || !$closed || [] !== $asks;
        $redirect = [
            'id' => $projectId,
            ...$pageQuery,
            ...($shown ? ['_fragment' => 'inbox-item-'.$item->number] : []),
        ];

        return new InboxReturnTarget('app_project_inbox', $redirect, ShowInboxController::class, ['id' => $projectId, 'project' => $item->project], $pageQuery);
    }

    private function linkedDocument(InboxItem $item, Uuid $id): ?Document
    {
        $link = $item->documents->findFirst(static fn (int $key, InboxItemDocument $link): bool => (bool) $link->document->id?->equals($id));

        return $link?->document;
    }
}
