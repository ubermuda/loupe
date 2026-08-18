<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Review\Command\ShowReviewCommand;
use App\Module\Review\Command\ShowReviewHandler;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Fetch the current review state (verdict, status, comments) for a document.
 */
#[McpTool(name: 'document_get_review', description: 'Fetch the review state (verdict, status, threaded comments, and answered decision blocks) for a document\'s current version. Every comment and reply reports whether an agent or a human wrote it, and a comment may carry a replacement for the text it quotes.')]
final readonly class DocumentGetReviewTool
{
    public function __construct(
        private ShowReviewHandler $showReview,
        private ReviewSubjectResolver $subjects,
    ) {
    }

    /**
     * @param string $documentId the UUID of the document whose review to retrieve
     *
     * `verdict` is null when no review has been submitted for the current version yet
     *
     * A comment's `status` is one of pending, addressed or resolved and belongs to the whole
     * thread, so only the root comment reports it
     *
     * Each `id` is the value document_reply_to_comment and document_mark_comment_addressed take
     *
     * `author` is agent or human — the class of writer, not an identity, so no name or address
     * of a human reviewer is reported
     *
     * `replacement` is what the reviewer wants the quoted passage to become: null means they
     * proposed no edit, an empty string means delete the passage, and any other value is the
     * text to put in its place. Only root comments carry it. Loupe never edits the document —
     * applying these is your job: rewrite the markdown and call document_revise. On a comment
     * with a replacement the `quote` is the verbatim selected span, so it can be substituted
     * as-is; on every other comment it is widened to whole words for readability and must not
     * be used for a find-and-replace
     *
     * An `orphaned: true` comment's quote no longer appears in the document, so its replacement
     * has no target and cannot be applied — reply to it instead of guessing
     *
     * `decisions` lists every decision block in the current version. `selected` is null while a
     * decision is unanswered, and otherwise the option as it read when the reviewer chose it
     *
     * `selected_index` points into the same entry's `options`, and is null when the chosen option
     * is no longer among them — `answered_at_version` then says which version it came from
     *
     * Each decision's `id` is the one the document declared in its fence, and it is permanent:
     * changing it in a revision discards the answer keyed to the old one
     *
     * @return array{status: string, verdict: string|null, version: int, comments: list<array{id: string, quote: string, body: string, replacement: string|null, author: 'agent'|'human', status: string, orphaned: bool, thread: list<array{id: string, quote: string, body: string, author: 'agent'|'human', orphaned: bool}>}>, decisions: list<array{id: string, options: list<string>, selected: string|null, selected_index: int|null, answered_at: string|null, answered_at_version: int|null}>}
     */
    public function __invoke(string $documentId): array
    {
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_READ);

            return ($this->showReview)(new ShowReviewCommand($document));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The review could not be read. The error has been logged.', previous: $e);
        }
    }
}
