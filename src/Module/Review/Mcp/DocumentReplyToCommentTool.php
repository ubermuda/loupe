<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Account\Repository\UserRepository;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Security\McpBoundProjectVoter;
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
#[McpTool(name: 'document_reply_to_comment', description: 'Reply to a document-review comment. Takes a comment id from document_get_review; the reply is attributed to the agent, not to the document owner. Replying to a reply is rejected — reply to the thread root instead.')]
final readonly class DocumentReplyToCommentTool
{
    public function __construct(
        private ReplyToCommentHandler $replyToComment,
        private ReviewSubjectResolver $subjects,
        private UserRepository $users,
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
            $comment = $this->subjects->requireComment($commentId, McpBoundProjectVoter::COMMENT_WRITE);

            $reply = ($this->replyToComment)(new ReplyToCommentCommand(
                actor: $this->users->agent(),
                parent: $comment,
                body: $body,
            ));

            return ['id' => (string) $reply->id];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (DomainErrors $e) {
            throw new ToolCallException(\sprintf('The reply was rejected: %s.', implode(', ', array_values($e->errors))), previous: $e);
        } catch (\Throwable $e) {
            throw new ToolCallException('The reply could not be saved. The error has been logged.', previous: $e);
        }
    }
}
