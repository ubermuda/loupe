<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use App\Module\Review\Entity\Tag;
use Mcp\Exception\ToolCallException;

/**
 * Renders a handler's DomainErrors as the message an agent reads.
 *
 * DomainErrors carries translation keys, which are meaningless to a caller that
 * has no locale and no UI. An unmapped key falls back to a generic message
 * rather than leaking the key itself, so adding a new domain failure can never
 * put `review.foo.error.bar` in front of an agent.
 *
 * ToolCallErrorMessagesTest reads every literal key the Review module throws
 * and fails when one has no sentence here, so the fallback stays a safety net
 * rather than the normal path.
 */
final readonly class ToolCallErrorMessages
{
    public const string UNMAPPED = 'The request was rejected. The error has been logged.';

    public function forAgent(DomainErrors $errors): ToolCallException
    {
        $lines = [];
        foreach ($errors->errors as $argument => $key) {
            $lines[] = \sprintf('%s: %s', $argument, self::sentence($key));
        }

        return new ToolCallException(implode("\n", $lines), previous: $errors);
    }

    /**
     * The same shape for a rule a tool enforces at its own boundary, so an
     * agent reads one format whichever layer rejected the call.
     */
    public function forArgument(string $argument, string $message): ToolCallException
    {
        return new ToolCallException(\sprintf('%s: %s', $argument, $message));
    }

    private static function sentence(string $key): string
    {
        return match ($key) {
            'review.create.error.blank', 'review.rename.error.blank' => 'A document title must not be blank.',
            'review.create.error.too_long', 'review.rename.error.too_long' => \sprintf('A document title must be at most %d characters.', Document::MAX_TITLE_LENGTH),
            'review.revise.error.description_blank' => 'A description of what changed in this version is required.',
            'review.archive.error.reason_blank' => 'A reason for archiving the document is required.',
            'review.tags.error.too_long' => \sprintf('A tag name must be at most %d characters.', Tag::MAX_NAME_LENGTH),
            'review.series.error.name_required' => 'A series ordinal needs a series name beside it.',
            'review.series.error.too_long' => \sprintf('A series name must be at most %d characters.', Series::MAX_NAME_LENGTH),
            'review.series.error.ordinal_required' => 'A series name needs an ordinal beside it, counting from 1.',
            'review.series.error.ordinal_not_positive' => 'A series ordinal must be 1 or greater.',
            'review.series.error.ordinal_too_large' => \sprintf('A series ordinal must be at most %d.', Series::MAX_ORDINAL),
            'review.series.error.ordinal_taken' => 'Another document in that series already holds that ordinal.',
            'review.series.error.name_taken' => 'The project already has a series of that name.',
            'review.references.error.self_reference' => 'A document cannot reference itself.',
            'review.references.error.other_project' => 'A document can only reference documents in the same project.',
            'review.decision.error.stale_version' => 'The document changed since you read it. Read it again, then choose again.',
            'review.decision.error.unknown' => 'This version of the document holds no decision with that id.',
            'review.decision.error.unknown_option' => 'That decision offers no option with that index.',
            'review.section.error.stale_version' => 'The document changed since you read it. Read it again, then approve again.',
            'review.section.error.unknown' => 'This version of the document holds no section with that heading id.',
            'review.document.suggestion.error.no_anchor' => 'A suggestion must quote the text that it replaces.',
            'review.document.flash.verdict_none' => 'You left no verdict on this document, so there is none to withdraw.',
            'review.document.flash.verdict_already_withdrawn' => 'You already withdrew your verdict on this document.',
            'review.document.flash.verdict_invalid' => 'That verdict is not one this document accepts.',
            'comment.error.not_owner' => 'You can only comment on your own documents.',
            'comment.error.reply_empty' => 'A reply must not be blank.',
            'comment.error.reply_too_long' => \sprintf('A reply must be at most %d bytes.', ReplyToCommentHandler::MAX_BODY_BYTES),
            'comment.error.reply_to_reply' => 'A reply can only be added to the top-level comment of a thread.',
            'comment.error.resolve_reply' => 'Only the top-level comment of a thread can be resolved.',
            'comment.error.reopen_reply' => 'Only the top-level comment of a thread can be reopened.',
            'comment.error.reopen_not_resolved' => 'That comment is not resolved, so it cannot be reopened.',
            default => self::UNMAPPED,
        };
    }
}
