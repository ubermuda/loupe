<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Submit a revised Markdown document. Unresolved comments are carried forward by fuzzy re-anchoring;
 * comments whose quoted text no longer appears are flagged orphaned. Returns the re-anchoring summary.
 */
#[McpTool(name: 'document_revise', description: 'Submit a new Markdown version of a document, described by what changed in it. Open comments are re-anchored onto the new version; those whose quoted text no longer appears are flagged orphaned. Pass title to correct the document title at the same time.')]
final readonly class DocumentReviseTool
{
    public function __construct(
        private ReviseDocumentHandler $handler,
        private ReviewSubjectResolver $subjects,
        private ToolCallErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string      $documentId  The UUID of the document to revise
     * @param string      $markdown    The new Markdown content for the document
     * @param string      $description What changed in this version and why, in one or two sentences, for a reviewer who read the previous one — name what you rewrote, added or dropped, not that you revised it
     * @param string|null $title       A corrected title for the document; omit to keep the current one
     *
     * @return array{carried: int, orphaned: int}
     */
    public function __invoke(string $documentId, string $markdown, string $description, ?string $title = null): array
    {
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            // The size cap is a transport concern — it protects this endpoint
            // from a payload it should never parse, which is not a rule about
            // what a document may contain. Title and description are domain
            // rules and are enforced by the handler.
            if (\strlen($markdown) > DocumentCreateTool::MAX_MARKDOWN_BYTES) {
                throw new ToolCallException('The markdown content exceeds the maximum allowed size.');
            }

            return ($this->handler)(new ReviseDocumentCommand($document, $markdown, $description, $title));
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be revised. The error has been logged.', previous: $e);
        }
    }
}
