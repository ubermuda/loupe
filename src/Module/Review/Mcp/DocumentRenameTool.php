<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Command\RenameDocumentCommand;
use App\Module\Review\Command\RenameDocumentHandler;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Retitle a document without submitting content, so a naming scheme can be
 * corrected across documents that already exist without minting a version for
 * each. Renames one document per call; loop to fix a batch.
 */
#[McpTool(name: 'document_rename', description: 'Change a document\'s title without creating a new version. Renames one document per call.')]
final readonly class DocumentRenameTool
{
    public function __construct(
        private RenameDocumentHandler $handler,
        private ReviewSubjectResolver $subjects,
        private ToolCallErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string $documentId The UUID of the document to rename
     * @param string $title      The new document title
     *
     * @return array{documentId: string, title: string}
     */
    public function __invoke(string $documentId, string $title): array
    {
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            ($this->handler)(new RenameDocumentCommand($document, $title));

            return ['documentId' => (string) $document->id, 'title' => $document->title];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be renamed. The error has been logged.', previous: $e);
        }
    }
}
