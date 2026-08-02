<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Module\Project\Entity\Project;
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
 * Resolves what an MCP tool call is allowed to act on: the project bound to the
 * authenticating token, and the documents and comments inside it. Shared by
 * every MCP tool so the rejection messages have a single source of truth.
 *
 * Collaborators are passed in rather than read off the using class, because a
 * trait cannot declare constructor-injected dependencies.
 */
trait ResolvesBoundProject
{
    private function requireBoundProject(AuthenticatedProjectResolver $projectResolver): Project
    {
        return $projectResolver->resolveMcpProject()
            ?? throw new ToolCallException('MCP token is not bound to a project. Mint a project token from the Connect page.');
    }

    private function requireDocument(
        string $documentId,
        AuthenticatedProjectResolver $projectResolver,
        DocumentRepository $documents,
        AuthorizationCheckerInterface $authorization,
    ): Document {
        // An unbound token is a setup mistake with its own fix, so it is
        // reported before the scope check turns it into "not accessible".
        $this->requireBoundProject($projectResolver);

        $document = $documents->find($this->parseId($documentId, 'document'));

        if (null === $document || !$authorization->isGranted(McpBoundProjectVoter::DOCUMENT_ACCESS, $document)) {
            // Deliberately identical for "does not exist" and "belongs to
            // another project", so a tool cannot be used to probe what exists
            // outside the token's project.
            throw new ToolCallException(\sprintf('Document "%s" not found or not accessible.', $documentId));
        }

        return $document;
    }

    private function requireComment(
        string $commentId,
        AuthenticatedProjectResolver $projectResolver,
        CommentRepository $comments,
        AuthorizationCheckerInterface $authorization,
    ): Comment {
        $this->requireBoundProject($projectResolver);

        $comment = $comments->find($this->parseId($commentId, 'comment'));

        if (null === $comment || !$authorization->isGranted(McpBoundProjectVoter::COMMENT_ACCESS, $comment)) {
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
