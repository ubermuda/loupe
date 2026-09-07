<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Command\MarkCommentAddressedOutcome;
use App\Module\Review\Command\MarkCommentsAddressedCommand;
use App\Module\Review\Command\MarkCommentsAddressedHandler;
use App\Module\Review\Entity\Comment;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * Pending → Addressed, and nothing else. Addressed is the agent's claim that it
 * acted; Resolved is the human agreeing the thread is finished, so no MCP tool
 * writes it.
 */
#[McpTool(name: 'document_mark_comment_addressed', description: 'Mark document-review comment threads as addressed after acting on them. Accepts the root comment ids returned by document_get_review, and only ids from the document\'s current version — revising a document mints new comment rows, so re-read the review after document_revise. Ids that are unknown, superseded by a newer version, already addressed, already resolved, or point at a reply rather than a thread root are skipped, not fatal.')]
final readonly class DocumentMarkCommentAddressedTool
{
    use ResolvesBoundProject;

    public function __construct(
        private ReviewSubjectResolver $subjects,
        private MarkCommentsAddressedHandler $markCommentsAddressed,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * `string[]` not `list<string>`: the SDK infers a parameter's JSON-schema
     * `items` from the docblock type and parses only the `T[]` and `array<T>`
     * spellings, so `list<string>` publishes an array of anything.
     *
     * @param string[] $commentIds root comment ids from document_get_review
     *
     * @return array{addressed: list<string>, skipped: list<array{id: string, reason: string}>}
     */
    public function __invoke(array $commentIds): array
    {
        $addressed = [];
        $skipped = [];

        try {
            // Rejected up front rather than per id: an unbound token is a setup
            // mistake, and letting the loop below absorb it would report every
            // id as not found instead.
            $this->requireBoundProject($this->projectResolver);

            /** @var list<array{id: string, reason: ?string}> $plan one entry per id, in the order given */
            $plan = [];
            /** @var list<Comment> $comments the ids that resolved, in the same order */
            $comments = [];

            foreach ($commentIds as $id) {
                if (!Uuid::isValid($id)) {
                    $plan[] = ['id' => $id, 'reason' => 'invalid_id'];
                    continue;
                }

                try {
                    $comments[] = $this->subjects->requireComment($id, McpBoundProjectVoter::COMMENT_WRITE);
                    $plan[] = ['id' => $id, 'reason' => null];
                } catch (ToolCallException) {
                    // One unreachable id must not abandon the rest of the batch.
                    // Same reason for "does not exist" and "another project", so
                    // the tolerant shape cannot be used to probe what exists.
                    $plan[] = ['id' => $id, 'reason' => 'not_found'];
                }
            }

            $outcomes = ($this->markCommentsAddressed)(new MarkCommentsAddressedCommand($comments));

            $next = 0;
            foreach ($plan as $entry) {
                $reason = $entry['reason'];

                if (null === $reason) {
                    // No default arm: an outcome added later must be an unhandled
                    // match here rather than be silently reported as addressed.
                    $reason = match ($outcomes[$next++] ?? throw new \LogicException('handler returned fewer outcomes than comments')) {
                        MarkCommentAddressedOutcome::Addressed => null,
                        MarkCommentAddressedOutcome::Superseded => 'superseded',
                        MarkCommentAddressedOutcome::IsReply => 'is_reply',
                        MarkCommentAddressedOutcome::AlreadyAddressed => 'already_addressed',
                        MarkCommentAddressedOutcome::AlreadyResolved => 'already_resolved',
                        MarkCommentAddressedOutcome::NotFound => 'not_found',
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
