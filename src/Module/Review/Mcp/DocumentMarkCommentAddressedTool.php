<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Security\McpBoundProjectVoter;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * Pending → Addressed, and nothing else. Addressed is the agent's claim that it
 * acted; Resolved is the human agreeing the thread is finished, so no MCP tool
 * writes it.
 */
#[McpTool(name: 'document_mark_comment_addressed', description: 'Mark document-review comment threads as addressed after acting on them. Accepts the root comment ids returned by document_get_review. Ids that are unknown, already addressed, already resolved, or point at a reply rather than a thread root are skipped, not fatal.')]
final readonly class DocumentMarkCommentAddressedTool
{
    use ResolvesBoundProject;

    public function __construct(
        private ReviewSubjectResolver $subjects,
        private EntityManagerInterface $em,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * @param list<string> $commentIds root comment ids from document_get_review
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

            foreach ($commentIds as $id) {
                if (!Uuid::isValid($id)) {
                    $skipped[] = ['id' => $id, 'reason' => 'invalid_id'];
                    continue;
                }

                try {
                    $comment = $this->subjects->requireComment($id, McpBoundProjectVoter::COMMENT_WRITE);
                } catch (ToolCallException) {
                    // One unreachable id must not abandon the rest of the batch.
                    // Same reason for "does not exist" and "another project", so
                    // the tolerant shape cannot be used to probe what exists.
                    $skipped[] = ['id' => $id, 'reason' => 'not_found'];
                    continue;
                }

                // Status lives on the thread root, so a reply has no status of
                // its own to move. Checked before the status branch below:
                // threadStatus reads through to the root and would make a reply
                // in a pending thread look eligible.
                if (null !== $comment->parent) {
                    $skipped[] = ['id' => $id, 'reason' => 'is_reply'];
                    continue;
                }

                if (CommentStatus::Pending !== $comment->status) {
                    $skipped[] = ['id' => $id, 'reason' => match ($comment->status) {
                        CommentStatus::Addressed => 'already_addressed',
                        default => 'already_resolved',
                    }];
                    continue;
                }

                $comment->status = CommentStatus::Addressed;
                $addressed[] = $id;
            }

            $this->em->flush();
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The comments could not be marked as addressed. The error has been logged.', previous: $e);
        }

        return ['addressed' => $addressed, 'skipped' => $skipped];
    }
}
