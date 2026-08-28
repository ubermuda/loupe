<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Mcp;

use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Security\SiteReviewMcpBoundProjectVoter;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * The agent's only write: Pending → Addressed. Resolved is reserved for the
 * human in the web UI.
 *
 * What keeps it that way is routing and firewall configuration: no tool calls
 * the resolve path, and ApiTokenAuthenticator is registered only on the `mcp`
 * and `api` firewalls, so a Bearer token cannot authenticate against the
 * resolve route at all (that route additionally carries a session-backed CSRF
 * token). SiteReviewCommentVoter would not stop one — an MCP request
 * authenticates as the project owner, which is its entire rule.
 *
 * Which project a comment id may belong to is a separate question, and
 * SiteReviewSubjectResolver answers it against the token's binding rather than
 * against ownership.
 */
#[McpTool(name: 'site_review_mark_comment_addressed', description: 'Mark site-review comments as addressed after fixing them. Accepts the comment ids returned by site_review_get. Comments that are unknown, already addressed, or resolved are skipped, not fatal.')]
final readonly class SiteReviewMarkCommentAddressedTool
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private SiteReviewSubjectResolver $subjects,
    ) {
    }

    /**
     * `string[]` not `list<string>`: the SDK infers a parameter's JSON-schema
     * `items` from the docblock type and parses only the `T[]` and `array<T>`
     * spellings, so `list<string>` publishes an array of anything.
     *
     * @param string[] $commentIds comment ids from site_review_get
     *
     * @return array{addressed: list<string>, skipped: list<array{id: string, reason: string}>}
     */
    public function __invoke(array $commentIds): array
    {
        $addressed = [];
        $skipped = [];

        try {
            // An unbound token is rejected once, rather than as one "unknown"
            // per id — and even when the batch is empty.
            $this->subjects->requireProject();

            // One transaction for the batch: each id is now written as it is
            // decided, so without this a failure partway through would leave
            // the earlier ids addressed while the call reports an error.
            $this->em->wrapInTransaction(function () use ($commentIds, &$addressed, &$skipped): void {
                foreach ($commentIds as $id) {
                    try {
                        $uuid = Uuid::fromString($id);
                    } catch (\InvalidArgumentException) {
                        $skipped[] = ['id' => $id, 'reason' => 'invalid_id'];
                        continue;
                    }

                    $comment = $this->subjects->findComment($uuid, SiteReviewMcpBoundProjectVoter::WRITE);
                    if (null === $comment) {
                        $skipped[] = ['id' => $id, 'reason' => 'unknown'];
                        continue;
                    }
                    if (SiteReviewCommentStatus::Pending !== $comment->status) {
                        $skipped[] = ['id' => $id, 'reason' => match ($comment->status) {
                            SiteReviewCommentStatus::Addressed => 'already_addressed',
                            default => 'resolved',
                        }];
                        continue;
                    }

                    // The status check above is advisory: it produces the precise
                    // skip reason, but a human can click Resolve between it and the
                    // write. Only the conditional UPDATE decides.
                    if (!$this->siteReviewComments->markAddressedIfPending($comment)) {
                        $skipped[] = ['id' => $id, 'reason' => match ($this->siteReviewComments->currentStatus($comment)) {
                            SiteReviewCommentStatus::Addressed => 'already_addressed',
                            SiteReviewCommentStatus::Resolved => 'resolved',
                            default => 'unknown',
                        }];
                        continue;
                    }

                    $addressed[] = $id;
                }
            });
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The comments could not be marked as addressed. The error has been logged.', previous: $e);
        }

        return ['addressed' => $addressed, 'skipped' => $skipped];
    }
}
