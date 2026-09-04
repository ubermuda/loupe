<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Mcp;

use App\Module\SiteReview\Command\MarkSiteReviewCommentAddressedOutcome;
use App\Module\SiteReview\Command\MarkSiteReviewCommentsAddressedCommand;
use App\Module\SiteReview\Command\MarkSiteReviewCommentsAddressedHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Security\SiteReviewMcpBoundProjectVoter;
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
#[McpTool(name: 'site_review_mark_comment_addressed', description: 'Mark site-review comments as addressed after fixing them. Accepts the comment ids returned by site_review_get. Comments that are unknown, already addressed, or resolved are skipped, not fatal. The skip reason is best-effort: the write settles the status, and the reason comes from a separate read that can be stale when another writer changes the same comment at that moment.')]
final readonly class SiteReviewMarkCommentAddressedTool
{
    public function __construct(
        private MarkSiteReviewCommentsAddressedHandler $markCommentsAddressed,
        private SiteReviewSubjectResolver $subjects,
    ) {
    }

    /**
     * `string[]` not `list<string>`: the SDK infers a parameter's JSON-schema
     * `items` from the docblock type and parses only the `T[]` and `array<T>`
     * spellings, so `list<string>` publishes an array of anything.
     *
     * A `skipped` reason is best-effort. The conditional UPDATE settles the
     * status, and the reason comes from a second read that can be stale.
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

            /** @var list<array{id: string, reason: ?string}> $plan one entry per id, in the order given */
            $plan = [];
            /** @var list<SiteReviewComment> $comments the ids that resolved, in the same order */
            $comments = [];

            foreach ($commentIds as $id) {
                try {
                    $uuid = Uuid::fromString($id);
                } catch (\InvalidArgumentException) {
                    $plan[] = ['id' => $id, 'reason' => 'invalid_id'];
                    continue;
                }

                $comment = $this->subjects->findComment($uuid, SiteReviewMcpBoundProjectVoter::WRITE);
                if (null === $comment) {
                    $plan[] = ['id' => $id, 'reason' => 'unknown'];
                    continue;
                }

                $comments[] = $comment;
                $plan[] = ['id' => $id, 'reason' => null];
            }

            $outcomes = ($this->markCommentsAddressed)(new MarkSiteReviewCommentsAddressedCommand($comments));

            $next = 0;
            foreach ($plan as $entry) {
                $reason = $entry['reason'];

                if (null === $reason) {
                    $reason = match ($outcomes[$next++] ?? throw new \LogicException('handler returned fewer outcomes than comments')) {
                        MarkSiteReviewCommentAddressedOutcome::Addressed => null,
                        MarkSiteReviewCommentAddressedOutcome::AlreadyAddressed => 'already_addressed',
                        MarkSiteReviewCommentAddressedOutcome::AlreadyResolved => 'resolved',
                        MarkSiteReviewCommentAddressedOutcome::NotFound => 'unknown',
                    };
                }

                if (null === $reason) {
                    $addressed[] = $entry['id'];
                    continue;
                }

                $skipped[] = ['id' => $entry['id'], 'reason' => $reason];
            }
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The comments could not be marked as addressed. The error has been logged.', previous: $e);
        }

        return ['addressed' => $addressed, 'skipped' => $skipped];
    }
}
