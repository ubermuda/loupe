<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
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
#[McpTool(name: 'document_mark_comment_addressed', description: 'Mark document-review comment threads as addressed after acting on them. Accepts the root comment ids returned by document_get_review, and only ids from the document\'s current version — revising a document mints new comment rows, so re-read the review after document_revise. Ids that are unknown, superseded by a newer version, already addressed, already resolved, or point at a reply rather than a thread root are skipped, not fatal.')]
final readonly class DocumentMarkCommentAddressedTool
{
    use ResolvesBoundProject;

    public function __construct(
        private ReviewSubjectResolver $subjects,
        private CommentRepository $comments,
        private EntityManagerInterface $em,
        private AuthenticatedProjectResolver $projectResolver,
        private DocumentVersionRepository $documentVersions,
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

            /** @var array<string, string> $currentVersionIds latest version id, keyed by document id */
            $currentVersionIds = [];

            // One transaction for the batch: each id is now written as it is
            // decided, so without this a failure partway through would leave
            // the earlier ids addressed while the call reports an error.
            $this->em->wrapInTransaction(function () use ($commentIds, &$currentVersionIds, &$addressed, &$skipped): void {
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

                    // Checked first, because a superseded id is wrong in a way the
                    // other reasons mask: a pre-revision id still resolves and still
                    // looks pending, but flipping it moves a row nobody reads while
                    // the live thread stays open.
                    $documentId = (string) $comment->version->document->id;
                    $currentVersionIds[$documentId] ??= (string) $this->documentVersions->findLatest($comment->version->document)->id;
                    if ($currentVersionIds[$documentId] !== (string) $comment->version->id) {
                        $skipped[] = ['id' => $id, 'reason' => 'superseded'];
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
                        // No default arm: a status added later must be an
                        // unhandled match here rather than be silently reported as
                        // already resolved.
                        $skipped[] = ['id' => $id, 'reason' => match ($comment->status) {
                            CommentStatus::Addressed => 'already_addressed',
                            CommentStatus::Resolved => 'already_resolved',
                        }];
                        continue;
                    }

                    // The status check above is advisory: it produces the precise
                    // skip reason, but a human can click Resolve between it and the
                    // write. Only the conditional UPDATE decides.
                    if (!$this->comments->markAddressedIfPending($comment)) {
                        $skipped[] = ['id' => $id, 'reason' => match ($this->comments->currentStatus($comment)) {
                            CommentStatus::Addressed => 'already_addressed',
                            CommentStatus::Resolved => 'already_resolved',
                            default => 'not_found',
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
