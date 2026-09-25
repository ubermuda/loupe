<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\SiteReview\Command\MarkSiteReviewCommentAddressedOutcome;
use App\Module\SiteReview\Command\MarkSiteReviewCommentsAddressedCommand;
use App\Module\SiteReview\Command\MarkSiteReviewCommentsAddressedHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Mcp\SiteReviewSubjectResolver;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * The agent's only write on feedback: Pending to Addressed. Resolved stays with
 * the human, and no MCP tool reaches the resolve route.
 */
#[McpTool(name: self::NAME, description: 'Mark feedback items as addressed after you fix them. Feedback is what a reviewer leaves on a page with the site-review widget, and each item belongs to a card. Pass the feedback ids from feedback_list or card_get. Only the feedback of the project bound to your MCP token is reachable. An item that is unknown, already addressed or resolved is skipped, not fatal. The skip reason is best-effort: the write settles the status, and the reason comes from a separate read that can be stale when another writer changes the same item at that moment.')]
final readonly class FeedbackMarkAddressedTool implements FlagGatedToolInterface
{
    public const string NAME = 'feedback_mark_addressed';

    public function __construct(
        private BoardFlagGate $gate,
        private SiteReviewSubjectResolver $subjects,
        private MarkSiteReviewCommentsAddressedHandler $markCommentsAddressed,
    ) {
    }

    #[\Override]
    public function gatedToolName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function requiredFlag(): string
    {
        return BoardInstallFlags::FLAG_BOARD_ENABLED;
    }

    /**
     * `string[]` not `list<string>`: the SDK parses only the `T[]` and
     * `array<T>` spellings, so `list<string>` publishes an array of anything.
     *
     * @param string[] $feedbackIds feedback ids from feedback_list or card_get
     *
     * @return array{addressed: list<string>, skipped: list<array{id: string, reason: string}>}
     */
    public function __invoke(array $feedbackIds): array
    {
        $this->gate->requireEnabled();

        $addressed = [];
        $skipped = [];

        try {
            // An unbound token is refused once, even for an empty batch.
            $this->subjects->requireProject();

            /** @var list<array{id: string, reason: ?string}> $plan one entry per id, in the order given */
            $plan = [];
            /** @var list<SiteReviewComment> $comments the ids that resolved, in the same order */
            $comments = [];

            foreach ($feedbackIds as $id) {
                try {
                    $uuid = Uuid::fromString($id);
                } catch (\InvalidArgumentException) {
                    $plan[] = ['id' => $id, 'reason' => 'invalid_id'];
                    continue;
                }

                $comment = $this->subjects->findComment($uuid, McpBoundProjectVoter::SITE_REVIEW_WRITE);
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
                $reason = $entry['reason'] ?? match ($outcomes[$next++] ?? throw new \LogicException('handler returned fewer outcomes than comments')) {
                    MarkSiteReviewCommentAddressedOutcome::Addressed => null,
                    MarkSiteReviewCommentAddressedOutcome::AlreadyAddressed => 'already_addressed',
                    MarkSiteReviewCommentAddressedOutcome::AlreadyResolved => 'resolved',
                    MarkSiteReviewCommentAddressedOutcome::NotFound => 'unknown',
                };

                if (null === $reason) {
                    $addressed[] = $entry['id'];
                    continue;
                }

                $skipped[] = ['id' => $entry['id'], 'reason' => $reason];
            }
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The feedback could not be marked as addressed. The error has been logged.', previous: $e);
        }

        return ['addressed' => $addressed, 'skipped' => $skipped];
    }
}
