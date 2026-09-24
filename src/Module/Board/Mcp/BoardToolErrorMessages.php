<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Bridge\Entity\WorkerRun;
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
            'board.card.error.pull_request_url_too_long' => \sprintf('A pull request URL must be at most %d characters.', CardPullRequest::MAX_URL_LENGTH),
            'board.card.error.document_unknown' => 'One of those document ids names no document of this project.',
            'board.card.error.linked_card_unknown' => 'One of those card ids names no card of this project.',
            'board.card.error.linked_card_self' => 'A card cannot link to itself. Remove its own id from relatedCards.',
            'board.card.error.linked_card_twice' => 'The same card appears twice in relatedCards. Name each card once, with one kind.',
            'board.card.error.column_gone' => 'That column no longer exists on this board. Name another column.',
            'board.card.error.run_name_blank' => 'Pass the name of the skill that runs the session, such as loupe:product-design.',
            'board.card.error.run_name_too_long' => \sprintf('A run name must be at most %d characters.', WorkerRun::MAX_RULE_NAME_LENGTH),
            'board.card.error.run_closed_by_move' => 'A move of the card closed the run right after it opened. Call card_get to read the card.',
            'board.card.error.search_query_blank' => 'Pass a query to search for. To read the whole board instead, call card_list.',
            default => self::UNMAPPED,
        };
    }
}
