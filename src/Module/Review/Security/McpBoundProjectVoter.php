<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Grants an MCP caller access to a document, comment or series only when it
 * belongs to the project its API token is bound to.
 *
 * This is a different question from the one DocumentVoter and CommentVoter
 * answer: those ask whether the *user* owns the subject, which would let a
 * token bound to one of a user's projects reach documents in another.
 *
 * Read and write are separate attributes even though the binding answers both
 * identically today, so restricting a token to reads later is a change to this
 * policy rather than an audit of every call site. A series carries a write
 * attribute alone, because nothing resolves one to read it.
 *
 * @extends Voter<'document.mcp_read'|'document.mcp_write'|'comment.mcp_read'|'comment.mcp_write'|'series.mcp_write', Comment|Document|Series>
 */
final class McpBoundProjectVoter extends Voter
{
    public const string DOCUMENT_READ = 'document.mcp_read';
    public const string DOCUMENT_WRITE = 'document.mcp_write';
    public const string COMMENT_READ = 'comment.mcp_read';
    public const string COMMENT_WRITE = 'comment.mcp_write';
    public const string SERIES_WRITE = 'series.mcp_write';

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
            self::SERIES_WRITE => $subject instanceof Series,
            default => false,
        };
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $boundProject = $this->projectResolver->resolveMcpProjectFor($token);
        $subjectProject = match (true) {
            $subject instanceof Document, $subject instanceof Series => $subject->project,
            default => $subject->version->document->project,
        };

        if ($boundProject === $subjectProject) {
            return true;
        }

        $this->auditor->record(
            'review.mcp_access_denied',
            AuditOutcome::Refused,
            [
                'attribute' => $attribute,
                'subjectId' => (string) $subject->id,
                'subjectProjectId' => (string) $subjectProject->id,
                'boundProjectId' => null === $boundProject ? null : (string) $boundProject->id,
            ],
            new AuditSubject($this->subjectType($subject), (string) $subject->id),
            Auditor::CATEGORY_SECURITY,
        );

        return false;
    }

    private function subjectType(Comment|Document|Series $subject): string
    {
        return match (true) {
            $subject instanceof Document => 'document',
            $subject instanceof Series => 'series',
            default => 'comment',
        };
    }
}
