<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Review\Repository\DocumentRepository;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * List all documents owned by the authenticated user.
 */
#[McpTool(name: 'list_documents', description: 'List all documents owned by the authenticated user, with their current status and version.')]
final readonly class ListDocumentsTool
{
    public function __construct(
        private DocumentRepository $documents,
        private Security $security,
    ) {
    }

    /**
     * @return list<array{documentId: string, title: string, status: string, currentVersion: int}>
     */
    public function __invoke(): array
    {
        // The ^/mcp firewall requires ROLE_USER, so an authenticated User is guaranteed here.
        /** @var User $user */
        $user = $this->security->getUser();

        $documents = $this->documents->findBy(['owner' => $user], ['createdAt' => 'DESC']);

        return array_map(
            static fn ($doc) => [
                'documentId' => (string) $doc->id,
                'title' => $doc->title,
                'status' => $doc->status->value,
                'currentVersion' => $doc->currentVersion()->versionNumber,
            ],
            $documents,
        );
    }
}
