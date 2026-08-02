<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Query\GetReview;
use App\Module\Review\Repository\DocumentRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Fetch the current review state (verdict, status, comments) for a document.
 */
#[McpTool(name: 'document_get_review', description: 'Fetch the review state (verdict, status, and threaded comments) for a document\'s current version.')]
final readonly class GetReviewTool
{
    use ResolvesBoundProject;

    public function __construct(
        private GetReview $getReview,
        private AuthenticatedProjectResolver $projectResolver,
        private DocumentRepository $documents,
        private AuthorizationCheckerInterface $authorization,
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
        $document = $this->requireDocument($documentId, $this->projectResolver, $this->documents, $this->authorization);

        try {
            return ($this->getReview)($document);
        } catch (\Throwable $e) {
            throw new ToolCallException('The review could not be read. The error has been logged.', previous: $e);
        }
    }
}
