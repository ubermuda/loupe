<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Security;

use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Entity\SiteReviewComment;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants an MCP caller a site review, or one of its comments, only when it
 * belongs to the project its API token is bound to.
 *
 * This is a different question from the one SiteReviewCommentVoter answers: it
 * asks whether the *user* owns the subject, which an MCP request satisfies by
 * construction because it authenticates as the project owner. So a token minted
 * for one project would reach every other project of the same owner; only the
 * binding is narrow enough to stop that.
 *
 * Read and write are separate attributes even though the binding answers both
 * identically today, so restricting a token to reads later is a change to this
 * policy rather than an audit of every call site.
 *
 * @extends Voter<'site_review.mcp_read'|'site_review.mcp_write', Project|SiteReviewComment>
 */
#[WithMonologChannel('app_security')]
final class SiteReviewMcpBoundProjectVoter extends Voter
{
    public const string READ = 'site_review.mcp_read';
    public const string WRITE = 'site_review.mcp_write';

    private const array SUPPORTED_ATTRIBUTES = [
        self::READ,
        self::WRITE,
    ];

    public function __construct(
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)
            && ($subject instanceof Project || $subject instanceof SiteReviewComment);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $boundProject = $this->projectResolver->resolveMcpProjectFor($token);
        $subjectProject = $subject instanceof Project ? $subject : $subject->project;

        if (null !== $boundProject && $boundProject === $subjectProject) {
            return true;
        }

        $this->logger->info('site_review.mcp.access_denied', [
            'attribute' => $attribute,
            'subjectId' => (string) $subject->id,
            'subjectProjectId' => (string) $subjectProject->id,
            'boundProjectId' => null === $boundProject ? null : (string) $boundProject->id,
        ]);

        return false;
    }
}
