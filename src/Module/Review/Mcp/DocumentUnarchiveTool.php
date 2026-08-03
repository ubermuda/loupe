<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Command\UnarchiveDocumentCommand;
use App\Module\Review\Command\UnarchiveDocumentHandler;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Restore an archived document to the default listing. The counterpart of
 * document_archive, and like it a metadata change that mints no version.
 */
#[McpTool(name: 'document_unarchive', description: 'Restore an archived document so it appears in the reviewer\'s default document list again. No new version is created, and the document\'s content and status are untouched. Unarchiving a document that is not archived changes nothing.')]
final readonly class DocumentUnarchiveTool
{
    public function __construct(
        private UnarchiveDocumentHandler $handler,
        private ReviewSubjectResolver $subjects,
        private ToolCallErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string $documentId The UUID of the document to restore
     *
     * @return array{documentId: string, archived: bool}
     */
    public function __invoke(string $documentId): array
    {
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            ($this->handler)(new UnarchiveDocumentCommand($document));

            return ['documentId' => (string) $document->id, 'archived' => null !== $document->archivedAt];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be restored. The error has been logged.', previous: $e);
        }
    }
}
