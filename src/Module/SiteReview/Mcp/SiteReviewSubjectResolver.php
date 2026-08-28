<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Security\SiteReviewMcpBoundProjectVoter;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the site and the comments a site-review MCP tool call may act on,
 * rejecting anything outside the project the authenticating token is bound to.
 *
 * Every caller-supplied identifier a tool accepts passes through here, so the
 * scoping rule is applied rather than remembered.
 */
final readonly class SiteReviewSubjectResolver
{
    use ResolvesBoundProject;

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
        private ProjectRepository $projects,
        private SiteReviewCommentRepository $siteReviewComments,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * The project a call acts on: the token's own binding, or the site the
     * caller named — which has to be that same project.
     */
    public function requireProject(?string $site = null): Project
    {
        // An unbound token is a setup mistake with its own fix, so it is
        // reported before the scope check turns it into "not accessible".
        $bound = $this->requireBoundProject($this->projectResolver);

        if (null === $site) {
            return $bound;
        }

        $named = $this->projects->findOneByIdOrNameForOwner($site, $bound->owner);

        if (null === $named || !$this->authorization->isGranted(SiteReviewMcpBoundProjectVoter::READ, $named)) {
            // Deliberately identical for "does not exist" and "belongs to
            // another project", so a tool cannot be used to probe what exists
            // outside the token's project.
            throw new ToolCallException(\sprintf('Site "%s" not found or not accessible.', $site));
        }

        return $named;
    }

    /**
     * The comment, or null when it does not exist or sits outside the token's
     * project. The two are one answer for the same anti-probing reason.
     *
     * @param SiteReviewMcpBoundProjectVoter::READ|SiteReviewMcpBoundProjectVoter::WRITE $attribute
     */
    public function findComment(Uuid $id, string $attribute): ?SiteReviewComment
    {
        $comment = $this->siteReviewComments->find($id);

        if (null === $comment || !$this->authorization->isGranted($attribute, $comment)) {
            return null;
        }

        return $comment;
    }
}
