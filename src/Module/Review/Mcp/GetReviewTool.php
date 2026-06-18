<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Review\Query\DocumentNotFound;
use App\Module\Review\Query\GetReview;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Fetch the current review state (verdict, status, comments) for a document.
 */
#[McpTool(name: 'get_review', description: 'Fetch the review state (verdict, status, and threaded comments) for a document\'s current version.')]
final readonly class GetReviewTool
{
    public function __construct(
        private GetReview $getReview,
        private Security $security,
    ) {
    }

    /**
     * @param string $documentId The UUID of the document whose review to retrieve
     *
     * @return array{status: string, verdict: string|null, version: int, comments: list<array{quote: string, body: string, resolved: bool, orphaned: bool, thread: list<array{quote: string, body: string, resolved: bool, orphaned: bool}>}>}
     */
    public function __invoke(string $documentId): array
    {
        /** @var User $user */
        $user = $this->security->getUser();

        try {
            return ($this->getReview)(Uuid::fromString($documentId), $user);
        } catch (DocumentNotFound $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        }
    }
}
