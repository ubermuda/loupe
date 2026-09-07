<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use Mcp\Exception\ToolCallException;

/**
 * Renders a handler's DomainErrors as the message an agent reads.
 *
 * DomainErrors carries translation keys, which mean nothing to a caller with no
 * locale and no UI. An unmapped key falls back to a generic message rather than
 * leaking the key itself.
 */
final readonly class BoardToolErrorMessages
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

    private static function sentence(string $key): string
    {
        return match ($key) {
            'board.card.error.title_blank' => 'A card title must not be blank.',
            'board.card.error.title_too_long' => \sprintf('A card title must be at most %d characters.', Card::MAX_TITLE_LENGTH),
            default => self::UNMAPPED,
        };
    }
}
