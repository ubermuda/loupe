<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Controller\ShowCardController;
use App\Module\Inbox\Controller\ShowInboxController;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Review\Controller\ShowDocumentController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * The page a response form returns the owner to: a redirect after a saved
 * response, and a forward that re-renders it after a refused one.
 *
 * The query names a page kind and an id. Only a card or document the item links
 * to is accepted, and the route is always built here, so the query can never
 * send the owner to an address of its choosing. Anything else returns to the inbox.
 */
final readonly class InboxReturnTarget
{
    public const string PAGE_QUERY = 'returnTo';
    public const string ID_QUERY = 'returnId';
    public const string VERSION_QUERY = 'returnVersion';

    /**
     * @param array<string, int|string> $routeParameters
     * @param array<string, mixed>      $attributes      what the forward hands the controller
     * @param array<string, int>        $query           what the forward keeps of the query string
     */
    private function __construct(
        public string $route,
        public array $routeParameters,
        public string $controller,
        public array $attributes,
        public array $query,
    ) {
    }

    public static function resolve(Request $request, InboxItem $item): self
    {
        $projectId = (string) $item->project->id;
        $page = InboxLinkedPage::tryFrom($request->query->getString(self::PAGE_QUERY));
        $id = $request->query->getString(self::ID_QUERY);

        if (null !== $page && Uuid::isValid($id) && self::links($item, $page, Uuid::fromString($id))) {
            $id = Uuid::fromString($id)->toRfc4122();

            $target = match ($page) {
                InboxLinkedPage::Card => ['projectId' => $projectId, 'cardId' => $id],
                InboxLinkedPage::Document => ['projectId' => $projectId, 'documentId' => $id],
            };
            // An older version of a document is a page of its own, and the owner stays on it.
            $version = InboxLinkedPage::Document === $page ? $request->query->getInt(self::VERSION_QUERY) : 0;
            if ($version >= 1) {
                $target['versionNumber'] = $version;
            }

            return match (true) {
                InboxLinkedPage::Card === $page => new self('app_board_card', $target, ShowCardController::class, $target, []),
                $version >= 1 => new self('app_document_review_version', $target, ShowDocumentController::class, $target, []),
                default => new self('app_document_review', $target, ShowDocumentController::class, $target, []),
            };
        }

        // The closed asks page the owner was on, kept on the way back.
        $pageQuery = $request->query->getInt('page', 1) > 1 ? ['page' => $request->query->getInt('page')] : [];

        return new self('app_project_inbox', ['id' => $projectId, ...$pageQuery], ShowInboxController::class, ['id' => $projectId, 'project' => $item->project], $pageQuery);
    }

    private static function links(InboxItem $item, InboxLinkedPage $page, Uuid $id): bool
    {
        $linked = match ($page) {
            InboxLinkedPage::Card => $item->cards->map(static fn ($link): ?Uuid => $link->card->id),
            InboxLinkedPage::Document => $item->documents->map(static fn ($link): ?Uuid => $link->document->id),
        };

        return $linked->exists(static fn (int $key, ?Uuid $linkedId): bool => null !== $linkedId && $linkedId->equals($id));
    }
}
