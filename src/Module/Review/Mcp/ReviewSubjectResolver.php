<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the documents and comments an MCP tool call may act on, rejecting
 * anything outside the project the authenticating token is bound to.
 *
 * A tool cannot reach a Document or a Comment without passing through here, so
 * the scoping rule is applied rather than remembered.
 */
final readonly class ReviewSubjectResolver
{
    use ResolvesBoundProject;

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
        private DocumentRepository $documents,
        private CommentRepository $comments,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @param McpBoundProjectVoter::DOCUMENT_* $attribute
     */
    public function requireDocument(string $documentId, string $attribute): Document
    {
        // An unbound token is a setup mistake with its own fix, so it is
        // reported before the scope check turns it into "not accessible".
        $this->requireBoundProject($this->projectResolver);

        $document = $this->documents->find($this->parseId($documentId, 'document'));

        if (null === $document || !$this->authorization->isGranted($attribute, $document)) {
            // Deliberately identical for "does not exist" and "belongs to
            // another project", so a tool cannot be used to probe what exists
            // outside the token's project.
            throw new ToolCallException(\sprintf('Document "%s" not found or not accessible.', $documentId));
        }

        return $document;
    }

    /**
     * Resolves the targets of a reference list. Pointing at a document is not a
     * write to it, so a read grant is enough — but the grant is still required,
     * which is what keeps a reference inside the token's project.
     *
     * @param array<string> $documentIds
     *
     * @return list<Document>
     */
    public function requireReferences(array $documentIds): array
    {
        return array_map(
            fn (string $id): Document => $this->requireDocument($id, McpBoundProjectVoter::DOCUMENT_READ),
            array_values($documentIds),
        );
    }

    /**
     * @param McpBoundProjectVoter::COMMENT_* $attribute
     */
    public function requireComment(string $commentId, string $attribute): Comment
    {
        $this->requireBoundProject($this->projectResolver);

        $comment = $this->comments->find($this->parseId($commentId, 'comment'));

        if (null === $comment || !$this->authorization->isGranted($attribute, $comment)) {
            throw new ToolCallException(\sprintf('Comment "%s" not found or not accessible.', $commentId));
        }

        return $comment;
    }

    private function parseId(string $id, string $subject): Uuid
    {
        try {
            return Uuid::fromString($id);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException(\sprintf('"%s" is not a valid %s ID.', $id, $subject), previous: $e);
        }
    }
}
