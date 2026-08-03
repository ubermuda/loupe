<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Review\Command\SetDocumentHighlightsCommand;
use App\Module\Review\Command\SetDocumentHighlightsHandler;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Lets the agent point at passages of a document instead of describing where
 * they are.
 *
 * A highlight is the counterpart to the quote a human reviewer's comment carries:
 * it names a span, so the reviewer's eye goes there. It is not a comment — no
 * body, no thread, nothing to reply to — because its only job is to say "read
 * this first", and anything the agent wants to say about the passage belongs in a
 * reply on the reviewer's own thread.
 */
#[McpTool(name: 'document_highlight', description: 'Highlight the passages of a document a reviewer should read first, so a long document is entered where it matters rather than at the top. Replaces the document\'s whole highlight set on every call; pass an empty list to clear it. Quote each passage exactly as it reads in rendered prose, NOT as Markdown source — "**must**" will not match, "must" will — and keep each quote inside a single paragraph or list item, because block boundaries are line breaks in the text quotes are matched against. A quote that appears more than once in the document lands on the FIRST occurrence and reports success either way, and repeating it in the list is skipped as a duplicate rather than reaching the second one, so extend the quote until it is unique when you mean a later one. Highlights belong to the version current at the time of the call and are dropped by document_revise, so restate them after revising. Quotes that cannot be located are reported back and skipped, not fatal.')]
final readonly class DocumentHighlightTool
{
    public function __construct(
        private ReviewSubjectResolver $subjects,
        private SetDocumentHighlightsHandler $setHighlights,
    ) {
    }

    /**
     * @param string       $documentId the id of the document to highlight, from document_list or document_create
     * @param list<string> $quotes     verbatim passages to highlight, as they read in rendered prose; an empty list clears every highlight
     *
     * @return array{highlighted: list<string>, skipped: list<array{quote: string, reason: string}>}
     */
    public function __invoke(string $documentId, array $quotes): array
    {
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            return ($this->setHighlights)(new SetDocumentHighlightsCommand(
                document: $document,
                quotes: array_values($quotes),
            ));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The highlights could not be saved. The error has been logged.', previous: $e);
        }
    }
}
