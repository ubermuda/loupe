<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Submit a revised Markdown document. Unresolved comments are carried forward by fuzzy re-anchoring;
 * comments whose quoted text no longer appears are flagged orphaned. Returns the re-anchoring summary.
 */
#[McpTool(name: 'document_revise', description: 'Submit a new Markdown version of a document. Open comments are re-anchored onto the new version; those whose quoted text no longer appears are flagged orphaned.')]
final readonly class ReviseDocumentTool
{
    public function __construct(
        private ReviseDocumentHandler $handler,
        private ReviewSubjectResolver $subjects,
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
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            if (\strlen($markdown) > CreateDocumentTool::MAX_MARKDOWN_BYTES) {
                throw new ToolCallException('The markdown content exceeds the maximum allowed size.');
            }

            return ($this->handler)(new ReviseDocumentCommand($document, $markdown));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be revised. The error has been logged.', previous: $e);
        }
    }
}
