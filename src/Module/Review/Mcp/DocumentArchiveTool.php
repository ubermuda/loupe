<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Command\ArchiveDocumentCommand;
use App\Module\Review\Command\ArchiveDocumentHandler;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Archive a document, which is metadata about where it appears rather than a
 * change to its content — so no version is minted, the same as renaming or
 * retagging.
 *
 * Archiving is curation: it decides which documents a reviewer sees by default.
 * The description says so plainly, because a caller that reaches for this to
 * tidy up its own output is making a decision that belongs to the reviewer.
 *
 * The reason is required here and optional everywhere else, for the same
 * reason: a person archiving from the app is standing in front of the document
 * they just read, while an agent doing it leaves nothing behind unless the
 * schema makes it.
 */
#[McpTool(name: 'document_archive', description: 'Archive a document, stating why. It disappears from the reviewer\'s default document list — document_list omits archived documents unless includeArchived is true — until document_unarchive restores it. Nothing is deleted and no new version is created. Archiving an already-archived document changes nothing, including its reason.')]
final readonly class DocumentArchiveTool
{
    public function __construct(
        private ArchiveDocumentHandler $handler,
        private ReviewSubjectResolver $subjects,
        private ToolCallErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string $documentId The UUID of the document to archive
     * @param string $reason     Why the document is being archived, shown to the reviewer beside it — e.g. "superseded by the v2 plan"
     *
     * @return array{documentId: string, archived: bool}
     */
    public function __invoke(string $documentId, string $reason): array
    {
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            ($this->handler)(new ArchiveDocumentCommand($document, $reason));

            return ['documentId' => (string) $document->id, 'archived' => null !== $document->archivedAt];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be archived. The error has been logged.', previous: $e);
        }
    }
}
