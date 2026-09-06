<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Project\Security\AuthenticatedProjectResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Grants an MCP caller a project-scoped subject only when it belongs to the
 * project its API token is bound to.
 *
 * This is a different question from the one the ownership voters answer.
 * DocumentVoter, SiteReviewCommentVoter and CardVoter ask whether the *user*
 * owns the subject, which an MCP request satisfies by construction because it
 * authenticates as the project owner. So a token minted for one project would
 * reach every other project of the same owner, and only the binding is narrow
 * enough to stop that.
 *
 * Read and write are separate attributes even though the binding answers both
 * identically today, so restricting a token to reads later is a change to this
 * policy rather than an audit of every call site. A series carries a write
 * attribute alone, because nothing resolves one to read it.
 *
 * The voter is generic on purpose. It reaches the project through
 * ProjectScopedSubject rather than through a module's entity classes, so one
 * policy and one audit shape serve every module that puts subjects on the MCP
 * surface.
 *
 * @extends Voter<'document.mcp_read'|'document.mcp_write'|'comment.mcp_read'|'comment.mcp_write'|'series.mcp_write'|'site_review.mcp_read'|'site_review.mcp_write'|'card.mcp_read'|'card.mcp_write', ProjectScopedSubject>
 */
final class McpBoundProjectVoter extends Voter
{
    public const string DOCUMENT_READ = 'document.mcp_read';
    public const string DOCUMENT_WRITE = 'document.mcp_write';
    public const string COMMENT_READ = 'comment.mcp_read';
    public const string COMMENT_WRITE = 'comment.mcp_write';
    public const string SERIES_WRITE = 'series.mcp_write';
    public const string SITE_REVIEW_READ = 'site_review.mcp_read';
    public const string SITE_REVIEW_WRITE = 'site_review.mcp_write';
    public const string CARD_READ = 'card.mcp_read';
    public const string CARD_WRITE = 'card.mcp_write';

    /**
     * Every attribute this policy covers, with the audit operation a refusal
     * records under and the subject types the attribute accepts.
     *
     * The operation name stays per module, because an audit trail whose names
     * change loses continuity with the rows already written. The subject-type
     * list keeps the voter as strict as one voter per module was: it abstains
     * on a pair no call site uses, such as a document attribute against a card.
     *
     * @var array<string, array{operation: string, subjectTypes: list<string>}>
     */
    private const array SCOPES = [
        self::DOCUMENT_READ => ['operation' => 'review.mcp_access_denied', 'subjectTypes' => ['document']],
        self::DOCUMENT_WRITE => ['operation' => 'review.mcp_access_denied', 'subjectTypes' => ['document']],
        self::COMMENT_READ => ['operation' => 'review.mcp_access_denied', 'subjectTypes' => ['comment']],
        self::COMMENT_WRITE => ['operation' => 'review.mcp_access_denied', 'subjectTypes' => ['comment']],
        self::SERIES_WRITE => ['operation' => 'review.mcp_access_denied', 'subjectTypes' => ['series']],
        self::SITE_REVIEW_READ => ['operation' => 'site_review.mcp_access_denied', 'subjectTypes' => ['project', 'site_review_comment']],
        self::SITE_REVIEW_WRITE => ['operation' => 'site_review.mcp_access_denied', 'subjectTypes' => ['project', 'site_review_comment']],
        self::CARD_READ => ['operation' => 'board.mcp_access_denied', 'subjectTypes' => ['card']],
        self::CARD_WRITE => ['operation' => 'board.mcp_access_denied', 'subjectTypes' => ['card']],
    ];

    public function __construct(
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly Auditor $auditor,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof ProjectScopedSubject
            && in_array($subject->scopedSubjectType(), self::SCOPES[$attribute]['subjectTypes'] ?? [], strict: true);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $boundProject = $this->projectResolver->resolveMcpProjectFor($token);
        $subjectProject = $subject->scopedProject();

        if (null !== $boundProject && $boundProject === $subjectProject) {
            return true;
        }

        $this->auditor->record(
            self::SCOPES[$attribute]['operation'],
            AuditOutcome::Refused,
            [
                'attribute' => $attribute,
                'subjectId' => (string) $subject->id,
                'subjectProjectId' => (string) $subjectProject->id,
                'boundProjectId' => null === $boundProject ? null : (string) $boundProject->id,
            ],
            new AuditSubject($subject->scopedSubjectType(), (string) $subject->id),
            Auditor::CATEGORY_SECURITY,
        );

        return false;
    }
}
