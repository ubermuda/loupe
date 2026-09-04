<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
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
 */
final readonly class ToolCallErrorMessages
{
    public function forAgent(DomainErrors $errors): ToolCallException
    {
        $key = array_first($errors->errors);

        return new ToolCallException(match ($key) {
            'review.rename.error.blank' => 'A document title must not be blank.',
            'review.rename.error.too_long' => \sprintf('A document title must be at most %d characters.', Document::MAX_TITLE_LENGTH),
            'review.revise.error.description_blank' => 'A description of what changed in this version is required.',
            'review.archive.error.reason_blank' => 'A reason for archiving the document is required.',
            'review.tags.error.too_long' => \sprintf('A tag name must be at most %d characters.', Tag::MAX_NAME_LENGTH),
            'review.series.error.name_required' => 'A series ordinal needs a series name beside it.',
            'review.series.error.too_long' => \sprintf('A series name must be at most %d characters.', Series::MAX_NAME_LENGTH),
            'review.series.error.ordinal_required' => 'A series name needs an ordinal beside it, counting from 1.',
            'review.series.error.ordinal_not_positive' => 'A series ordinal must be 1 or greater.',
            'review.series.error.ordinal_taken' => 'Another document in that series already holds that ordinal.',
            'review.series.error.name_taken' => 'The project already has a series of that name.',
            'review.references.error.self_reference' => 'A document cannot reference itself.',
            'review.references.error.other_project' => 'A document can only reference documents in the same project.',
            default => 'The request was rejected. The error has been logged.',
        }, previous: $errors);
    }
}
