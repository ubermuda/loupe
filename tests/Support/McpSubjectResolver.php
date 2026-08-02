<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Exposes the ResolvesBoundProject trait so its methods can be driven directly.
 *
 * The trait's methods are private, so they are otherwise only reachable through
 * whichever tools happen to call them — which leaves the comment resolver, used
 * by no tool yet, with no way to pin its behaviour.
 */
final readonly class McpSubjectResolver
{
    use ResolvesBoundProject;

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
        private DocumentRepository $documents,
        private CommentRepository $comments,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function document(string $documentId): Document
    {
        return $this->requireDocument($documentId, $this->projectResolver, $this->documents, $this->authorization);
    }

    public function comment(string $commentId): Comment
    {
        return $this->requireComment($commentId, $this->projectResolver, $this->comments, $this->authorization);
    }
}
