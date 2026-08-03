<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Review\Query\GetReview;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Fetch the current review state (verdict, status, comments) for a document.
 */
#[McpTool(name: 'document_get_review', description: 'Fetch the review state (verdict, status, and threaded comments) for a document\'s current version.')]
final readonly class DocumentGetReviewTool
{
    public function __construct(
        private GetReview $getReview,
        private ReviewSubjectResolver $subjects,
    ) {
    }

    /**
     * @param string $documentId the UUID of the document whose review to retrieve
     *
     * `verdict` is null when no review has been submitted for the current version yet
     *
     * A comment's `status` is one of pending, addressed or resolved and belongs to the whole
     * thread, so only the root comment reports it
     *
     * @return array{status: string, verdict: string|null, version: int, comments: list<array{quote: string, body: string, status: string, orphaned: bool, thread: list<array{quote: string, body: string, orphaned: bool}>}>}
     */
    public function __invoke(string $documentId): array
    {
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_READ);

            return ($this->getReview)($document);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The review could not be read. The error has been logged.', previous: $e);
        }
    }
}
