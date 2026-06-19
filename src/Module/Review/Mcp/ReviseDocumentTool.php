<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Query\DocumentNotFound;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Submit a revised Markdown document. Unresolved comments are carried forward by fuzzy re-anchoring;
 * comments whose quoted text no longer appears are flagged orphaned. Returns the re-anchoring summary.
 */
#[McpTool(name: 'revise_document', description: 'Submit a new Markdown version of a document. Open comments are re-anchored onto the new version; those whose quoted text no longer appears are flagged orphaned.')]
final readonly class ReviseDocumentTool
{
    public function __construct(
        private ReviseDocumentHandler $handler,
        private Security $security,
    ) {
    }

    /**
     * @param string $documentId The UUID of the document to revise
     * @param string $markdown   The new Markdown content for the document
     *
     * @return array{carried: int, orphaned: int}
     */
    public function __invoke(string $documentId, string $markdown): array
    {
        /** @var User $user */
        $user = $this->security->getUser();

        try {
            $uuid = Uuid::fromString($documentId);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException(\sprintf('"%s" is not a valid document ID.', $documentId), previous: $e);
        }

        try {
            return ($this->handler)(new ReviseDocumentCommand($uuid, $user, $markdown));
        } catch (DocumentNotFound $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        }
    }
}
