<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Mcp;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Entity\SiteReviewStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * The agent's only write: Pending → Addressed. Resolved is reserved for the
 * human in the web UI and is unreachable from MCP by design.
 */
#[McpTool(name: 'address_site_review_comments', description: 'Mark site-review comments as addressed after fixing them. Accepts the comment ids returned by get_site_review. Comments that are unknown, already addressed, or resolved are skipped, not fatal.')]
final readonly class AddressSiteReviewCommentsTool
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private Security $security,
    ) {
    }

    /**
     * @param list<string> $commentIds comment ids from get_site_review
     *
     * @return array{addressed: list<string>, skipped: list<array{id: string, reason: string}>}
     */
    public function __invoke(array $commentIds): array
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $addressed = [];
        $skipped = [];
        foreach ($commentIds as $id) {
            try {
                $uuid = Uuid::fromString($id);
            } catch (\InvalidArgumentException) {
                $skipped[] = ['id' => $id, 'reason' => 'invalid_id'];
                continue;
            }

            $comment = $this->siteReviewComments->findOneForOwner($uuid, $user);
            if (null === $comment) {
                $skipped[] = ['id' => $id, 'reason' => 'unknown'];
                continue;
            }
            if (SiteReviewStatus::Submitted !== $comment->review->status) {
                $skipped[] = ['id' => $id, 'reason' => 'not_submitted'];
                continue;
            }
            if (SiteReviewCommentStatus::Pending !== $comment->status) {
                $skipped[] = ['id' => $id, 'reason' => 'not_pending'];
                continue;
            }

            $comment->status = SiteReviewCommentStatus::Addressed;
            $addressed[] = $id;
        }

        $this->em->flush();

        return ['addressed' => $addressed, 'skipped' => $skipped];
    }
}
