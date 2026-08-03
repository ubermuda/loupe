<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Document;
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
            'review.revise.error.self_reference' => 'A document cannot reference itself.',
            default => 'The request was rejected. The error has been logged.',
        }, previous: $errors);
    }
}
