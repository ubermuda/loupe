<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Command\ConfigureBoardColumnHandler;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\ReorderBoardColumnsHandler;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Service\BoardColumns;
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

    /**
     * @param array<string, string> $argumentNames the tool argument that carries each handler field, where the names differ
     */
    public function forAgent(DomainErrors $errors, array $argumentNames = []): ToolCallException
    {
        // The form names the field parent; the tools name the argument parentCardId.
        $names = $argumentNames + ['parent' => 'parentCardId'];
        $lines = [];
        foreach ($errors->errors as $argument => $key) {
            $lines[] = \sprintf('%s: %s', $names[$argument] ?? $argument, self::sentence($key));
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
            'board.card.error.parent_unknown' => 'That parentCardId names no card of this project.',
            'board.card.error.parent_not_epic' => 'Only a card of type epic can be a parent. Name an epic, or set that card\'s type to epic first.',
            'board.card.error.epic_cannot_have_parent' => 'An epic cannot have a parent, because epics do not nest. Leave parentCardId out, or choose another type.',
            'board.card.error.parent_card_cannot_be_epic' => 'A card with a parent cannot become an epic. Clear its parent with an empty parentCardId first.',
            'board.card.error.epic_type_locked' => 'This epic has child cards, so its type stays epic. Clear the parent of each child first.',
            'board.card.error.epic_delete_has_children' => 'This epic has child cards, so it cannot be deleted. Delete each child, or clear its parent, first.',
            'board.card.error.column_gone' => 'That column no longer exists on this board. Name another column.',
            'board.card.error.run_name_blank' => 'Pass the name of the skill that runs the session, such as loupe:product-design.',
            'board.card.error.run_name_too_long' => \sprintf('A run name must be at most %d characters.', WorkerRun::MAX_WORK_KIND_LENGTH),
            'board.card.error.search_query_blank' => 'Pass a query to search for. To read the whole board instead, call card_list.',
            BoardColumns::SLUG_EMPTY => 'A column label needs at least one letter or digit, because the slug comes from the label.',
            BoardColumns::SLUG_INVALID => 'The slug that comes from this label is not valid. Use letters, digits and spaces in the label.',
            BoardColumns::SLUG_TAKEN => 'Another column on this board already has the slug that comes from this label. Choose another label.',
            BoardColumns::NO_TERMINAL => 'A board needs at least one terminal column. Mark another column terminal with column_update first.',
            BoardColumns::NO_SINGLE_BACKLOG => 'A board needs exactly one Backlog.',
            BoardColumns::BACKLOG_TERMINAL => 'The Backlog cannot be terminal.',
            BoardColumns::BACKLOG_SLUG => 'The Backlog must keep the slug backlog.',
            BoardColumns::BACKLOG_LOCKED => 'Backlog is not a column. Nobody can rename, change, reorder or delete it.',
            BoardColumns::LABEL_RESERVED => 'The app uses this text internally. Choose another label.',
            'board.column.error.label_too_long' => \sprintf('A column label must be at most %d characters.', BoardColumn::MAX_LABEL_LENGTH),
            ConfigureBoardColumnHandler::GONE => 'That column no longer exists. Call board_columns and try again.',
            ConfigureBoardColumnHandler::LABEL_STALE, ConfigureBoardColumnHandler::TERMINAL_STALE, ReorderBoardColumnsHandler::ORDER_STALE => 'The columns changed while the call ran. Call board_columns and try again.',
            DeleteBoardColumnHandler::TARGET_REQUIRED => 'This column holds cards. Pass targetColumn, the slug of the column they move to.',
            DeleteBoardColumnHandler::TARGET_INVALID => 'targetColumn must name another column of this board. Call board_columns and pass another slug.',
            SaveBoardAutomationSettingsHandler::AGENT_REVIEW_FAILING_SEVERITIES_INVALID => 'Pass one or more severities, each important, nit or pre-existing.',
            SaveBoardAutomationSettingsHandler::EPIC_BRANCH_PATTERN_INVALID => 'The pattern must be a branch name that holds {number} exactly once, such as epic/{number}, or be empty to turn epic branches off.',
            SaveBoardAutomationSettingsHandler::STUCK_DELAY_INVALID => \sprintf('stuckDelayMinutes must be a whole number of minutes from %d to %d.', BoardAutomationSettings::MIN_STUCK_DELAY_MINUTES, BoardAutomationSettings::MAX_STUCK_DELAY_MINUTES),
            default => self::UNMAPPED,
        };
    }
}
