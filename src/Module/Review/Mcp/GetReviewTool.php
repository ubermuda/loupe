<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Query\DocumentNotFound;
use App\Module\Review\Query\GetReview;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * Fetch the current review state (verdict, status, comments) for a document.
 */
#[McpTool(name: 'get_review', description: 'Fetch the review state (verdict, status, and threaded comments) for a document\'s current version.')]
final readonly class GetReviewTool
{
    use ResolvesBoundProject;

    public function __construct(
        private GetReview $getReview,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * @param string $documentId the UUID of the document whose review to retrieve
     *
     * `verdict` is null when no review has been submitted for the current version yet
     *
     * @return array{status: string, verdict: string|null, version: int, comments: list<array{quote: string, body: string, resolved: bool, orphaned: bool, thread: list<array{quote: string, body: string, resolved: bool, orphaned: bool}>}>}
     */
    public function __invoke(string $documentId): array
    {
        $project = $this->requireBoundProject($this->projectResolver);

        try {
            return ($this->getReview)(Uuid::fromString($documentId), $project);
        } catch (DocumentNotFound $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException(\sprintf('"%s" is not a valid document ID.', $documentId), previous: $e);
        }
    }
}
