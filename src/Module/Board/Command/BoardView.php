<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Service\CardBadge;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Project\Entity\Project;

/** Everything one board page renders. */
final readonly class BoardView
{
    /** @param list<BoardColumnView> $columns */
    public function __construct(
        public Project $project,
        public array $columns,
        public int $terminalWindowDays,
        /** The board draws no Backlog column, and its header button shows this count. */
        public BoardColumn $backlog,
        public int $backlogCount,
        /**
         * Unaddressed site-review comments per card id. A card with none is
         * absent rather than zero, so the template asks with a default. The
         * key is the id as a string, because a Uuid cannot be an array key.
         *
         * @var array<string, int>
         */
        public array $pendingComments = [],
        /**
         * Linked documents per card id, keyed and defaulted like $pendingComments.
         *
         * @var array<string, int>
         */
        public array $documentCounts = [],
        /** @var list<DeadBridgeRuleView> */
        public array $deadBridgeRules = [],
        /**
         * The column slugs a live bridge rule watches. A rename or a delete of
         * such a column stops the rule matching.
         *
         * @var list<string>
         */
        public array $watchedColumnSlugs = [],
        /**
         * The epic lanes, in board order. Empty when no epic has its lane on,
         * and the board then draws its columns alone.
         *
         * @var list<BoardLaneView>
         */
        public array $lanes = [],
        /** The row below the lanes for every card outside them. Null when there are no lanes. */
        public ?BoardLaneView $otherCards = null,
        /** @var array<string, CardProgress> epic id => its progress, for every epic on the board; any other card has no key */
        public array $progress = [],
        /**
         * How many cards the board draws per column id. A lane epic is its
         * lane header rather than a card, so this can be less than the count.
         *
         * @var array<string, int>
         */
        public array $shownCounts = [],
        /** A hash of the columns and the lanes, which the page compares after it reconnects. */
        public string $structureDigest = '',
        /** @var array<string, CardRunWarning> card id => the warning its last run left */
        public array $runWarnings = [],
        /** @var array<string, LaneDeckView> lane epic id => its Up next deck; an epic with no Backlog child has no key */
        public array $decks = [],
        /** @var array<string, non-empty-list<CardBadge>> card id => its badges; a card with none has no key */
        public array $badges = [],
        /** @var list<RacingBridgeRuleView> */
        public array $racingBridgeRules = [],
        /** @var array<string, string> RFC 4122 bridge id => its label, for the bridges of the problem rules */
        public array $bridgeLabels = [],
    ) {
    }
}
