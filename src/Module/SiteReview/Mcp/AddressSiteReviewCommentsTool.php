<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\Uid\Uuid;

/**
 * The agent's only write: Pending → Addressed. Resolved is reserved for the
 * human in the web UI and is unreachable from MCP by design.
 */
#[McpTool(name: 'site_review_mark_comment_addressed', description: 'Mark site-review comments as addressed after fixing them. Accepts the comment ids returned by site_review_get. Comments that are unknown, already addressed, or resolved are skipped, not fatal.')]
final readonly class AddressSiteReviewCommentsTool
{
    use ResolvesBoundProject;

    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * @param list<string> $commentIds comment ids from site_review_get
     *
     * @return array{addressed: list<string>, skipped: list<array{id: string, reason: string}>}
     */
    public function __invoke(array $commentIds): array
    {
        $project = $this->requireBoundProject($this->projectResolver);

        $addressed = [];
        $skipped = [];
        foreach ($commentIds as $id) {
            try {
                $uuid = Uuid::fromString($id);
            } catch (\InvalidArgumentException) {
                $skipped[] = ['id' => $id, 'reason' => 'invalid_id'];
                continue;
            }

            $comment = $this->siteReviewComments->findOneForProject($uuid, $project);
            if (null === $comment) {
                $skipped[] = ['id' => $id, 'reason' => 'unknown'];
                continue;
            }
            if (SiteReviewCommentStatus::Pending !== $comment->status) {
                $skipped[] = ['id' => $id, 'reason' => match ($comment->status) {
                    SiteReviewCommentStatus::Draft => 'not_submitted',
                    SiteReviewCommentStatus::Addressed => 'already_addressed',
                    default => 'resolved',
                }];
                continue;
            }

            $comment->status = SiteReviewCommentStatus::Addressed;
            $addressed[] = $id;
        }

        $this->em->flush();

        return ['addressed' => $addressed, 'skipped' => $skipped];
    }
}
