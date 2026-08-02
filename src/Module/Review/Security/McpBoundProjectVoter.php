<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants an MCP caller access to a document or comment only when it belongs to
 * the project its API token is bound to.
 *
 * This is a different question from the one DocumentVoter and CommentVoter
 * answer: those ask whether the *user* owns the subject, which would let a
 * token bound to one of a user's projects reach documents in another.
 *
 * @extends Voter<'document.mcp_access'|'comment.mcp_access', Comment|Document>
 */
final class McpBoundProjectVoter extends Voter
{
    public const string DOCUMENT_ACCESS = 'document.mcp_access';
    public const string COMMENT_ACCESS = 'comment.mcp_access';

    public function __construct(
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::DOCUMENT_ACCESS => $subject instanceof Document,
            self::COMMENT_ACCESS => $subject instanceof Comment,
            default => false,
        };
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $boundProject = $this->projectResolver->resolveMcpProjectFor($token);
        $subjectProject = $subject instanceof Document
            ? $subject->project
            : $subject->version->document->project;

        if ($boundProject === $subjectProject) {
            return true;
        }

        $this->logger->info('review.mcp.access_denied', [
            'attribute' => $attribute,
            'subjectId' => (string) $subject->id,
            'subjectProjectId' => (string) $subjectProject->id,
            'boundProjectId' => null === $boundProject ? null : (string) $boundProject->id,
        ]);

        return false;
    }
}
