<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Command\ReplyToCommentAsAgentCommand;
use App\Module\Review\Command\ReplyToCommentAsAgentHandler;
use App\Module\Review\Command\StaleCommentVersion;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Writes an agent reply into a document-review thread.
 *
 * The reply is authored by the singleton agent user, not by the human whose
 * token carried the call: an MCP request authenticates as the project owner, so
 * attributing it to the token holder would show the reviewer saying things they
 * never wrote.
 */
#[McpTool(name: 'document_reply_to_comment', description: 'Reply to a document-review comment. Takes a comment id from document_get_review, and only an id from the document\'s current version — revising a document mints new comment rows, so reply before calling document_revise, or re-read the review after it. The reply is attributed to the agent, not to the document owner. Replying to a reply is rejected: reply to the thread root instead.')]
final readonly class DocumentReplyToCommentTool
{
    public function __construct(
        private ReplyToCommentAsAgentHandler $replyAsAgent,
        private ReviewSubjectResolver $subjects,
        private ToolCallErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string $commentId the id of the comment to reply to, from document_get_review
     * @param string $body      the reply text
     *
     * @return array{id: string}
     */
    public function __invoke(string $commentId, string $body): array
    {
        try {
            $reply = ($this->replyAsAgent)(new ReplyToCommentAsAgentCommand(
                parent: $this->subjects->requireComment($commentId, McpBoundProjectVoter::COMMENT_WRITE),
                body: $body,
            ));

            return ['id' => (string) $reply->id];
        } catch (StaleCommentVersion $e) {
            throw new ToolCallException(\sprintf('Comment "%s" belongs to version %d, but the document is now on version %d. Call document_get_review again for the current version\'s comment ids.', $commentId, $e->commentVersionNumber, $e->currentVersionNumber));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (\Throwable $e) {
            throw new ToolCallException('The reply could not be saved. The error has been logged.', previous: $e);
        }
    }
}
