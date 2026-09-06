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
 * comments whose quoted text no longer appears are flagged orphaned. A section approval survives only
 * while its heading and its text both read as before. Returns both summaries.
 *
 * @phpstan-import-type RevisionSummary from ReviseDocumentHandler
 */
#[McpTool(name: 'document_revise', description: 'Submit a new Markdown version of a document, described by what changed in it. Open comments are re-anchored onto the new version; those whose quoted text no longer appears are flagged orphaned. Approved sections whose heading and text are unchanged carry forward; the rest are dropped. Pass title to correct the document title at the same time, and references to replace the documents this one points at. Pass series with seriesOrdinal to move the document to a place in an ordered set, or an empty series to take it out of one.')]
final readonly class DocumentReviseTool
{
    public function __construct(
        private ReviseDocumentHandler $handler,
        private ReviewSubjectResolver $subjects,
        private ToolCallErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string             $documentId    The UUID of the document to revise
     * @param string             $markdown      The new Markdown content for the document
     * @param string             $description   What changed in this version and why, in one or two sentences, for a reviewer who read the previous one — name what you rewrote, added or dropped, not that you revised it
     * @param string|null        $title         A corrected title for the document; omit to keep the current one
     * @param array<string>|null $references    The complete set of document ids this one points at, replacing the current set; omit to keep it, pass an empty list to clear it
     * @param string|null        $series        Name of the ordered set this document belongs to, stored as you spell it and created if the project does not have it yet; omit to leave the placement alone, pass an empty string to take the document out of its series
     * @param int|null           $seriesOrdinal Position of this document in that series, counting from 1; required whenever series is given
     *
     * @return RevisionSummary
     */
    public function __invoke(string $documentId, string $markdown, string $description, ?string $title = null, ?array $references = null, ?string $series = null, ?int $seriesOrdinal = null): array
    {
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            // The size cap is a transport concern — it protects this endpoint
            // from a payload it should never parse, which is not a rule about
            // what a document may contain. Title and description are domain
            // rules and are enforced by the handler.
            if (\strlen($markdown) > DocumentCreateTool::MAX_MARKDOWN_BYTES) {
                throw $this->errorMessages->forArgument('markdown', 'The markdown content exceeds the maximum allowed size.');
            }

            // Named, not positional: the command now ends in four optional
            // parameters of mixed type, so a mis-ordered call would be silent.
            return ($this->handler)(new ReviseDocumentCommand(
                document: $document,
                markdown: $markdown,
                description: $description,
                title: $title,
                references: null === $references ? null : $this->subjects->requireReferences($references),
                seriesName: $series,
                seriesOrdinal: $seriesOrdinal,
            ));
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be revised. The error has been logged.', previous: $e);
        }
    }
}
