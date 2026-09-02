<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
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
 * Read and write are separate attributes even though the binding answers both
 * identically today, so restricting a token to reads later is a change to this
 * policy rather than an audit of every call site.
 *
 * @extends Voter<'document.mcp_read'|'document.mcp_write'|'comment.mcp_read'|'comment.mcp_write', Comment|Document>
 */
final class McpBoundProjectVoter extends Voter
{
    public const string DOCUMENT_READ = 'document.mcp_read';
    public const string DOCUMENT_WRITE = 'document.mcp_write';
    public const string COMMENT_READ = 'comment.mcp_read';
    public const string COMMENT_WRITE = 'comment.mcp_write';

    public function __construct(
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly Auditor $auditor,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::DOCUMENT_READ, self::DOCUMENT_WRITE => $subject instanceof Document,
            self::COMMENT_READ, self::COMMENT_WRITE => $subject instanceof Comment,
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

        $this->auditor->record(
            'review.mcp.access_denied',
            AuditOutcome::Refused,
            [
                'attribute' => $attribute,
                'subjectId' => (string) $subject->id,
                'subjectProjectId' => (string) $subjectProject->id,
                'boundProjectId' => null === $boundProject ? null : (string) $boundProject->id,
            ],
            new AuditSubject($subject instanceof Document ? 'document' : 'comment', (string) $subject->id),
            Auditor::CATEGORY_SECURITY,
        );

        return false;
    }
}
