<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Repository\CardRepository;

final readonly class ShowBoardHandler
{
    /**
     * How far back the Done column reads.
     *
     * Done only ever grows, so the column shows a recent slice and the history
     * page carries the rest.
     */
    public const int DONE_WINDOW_DAYS = 7;

    public function __construct(
        private CardRepository $cards,
    ) {
    }

    public function __invoke(ShowBoardCommand $command): BoardView
    {
        $project = $command->project;
        $columns = [];

        foreach (CardStatus::cases() as $status) {
            if (CardStatus::Done === $status) {
                $recent = $this->cards->findDoneSince(
                    $project,
                    new \DateTimeImmutable(\sprintf('-%d days', self::DONE_WINDOW_DAYS)),
                );

                $columns[] = new BoardColumnView($status, [new BoardGroupView(null, $recent)], \count($recent), rankable: false);

                continue;
            }

            // One query per column rather than one per priority group: the read
            // already comes back ordered by priority then position, so the
            // grouping below only has to split a list that is in board order.
            $cards = $this->cards->findForBoard($project, $status);

            $groups = [];
            foreach (CardPriority::cases() as $priority) {
                $groups[] = new BoardGroupView($priority, array_values(array_filter(
                    $cards,
                    static fn (Card $card): bool => $card->priority === $priority,
                )));
            }

            $columns[] = new BoardColumnView($status, $groups, \count($cards), rankable: true);
        }

        return new BoardView($project, $columns, $this->cards->countDone($project), self::DONE_WINDOW_DAYS);
    }
}
