<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardMover;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Moves the cards ticked on one page of the Backlog to one column, all or none.
 *
 * Each move runs through UpdateCardHandler, whose transaction nests as a
 * savepoint in this one, so one refusal rolls every move back.
 */
final readonly class BulkMoveBacklogCardsHandler
{
    /** One page of the Backlog page. */
    public const int MAX_CARDS = ListBacklogCardsHandler::PER_PAGE;

    public const string NONE_CHOSEN = 'board.backlog.error.none_chosen';
    public const string TOO_MANY = 'board.backlog.error.too_many';

    public function __construct(
        private CardRepository $cards,
        private UpdateCardHandler $updateCard,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return list<Card> the moved cards, in the order they left the Backlog */
    public function __invoke(BulkMoveBacklogCardsCommand $command): array
    {
        $cardIds = array_values(array_unique(array_map(
            static fn (string $cardId): string => strtolower(trim($cardId)),
            $command->cardIds,
        )));
        if ([] === $cardIds) {
            throw new DomainErrors(['ids' => self::NONE_CHOSEN]);
        }
        if (\count($cardIds) > self::MAX_CARDS) {
            throw new DomainErrors(['ids' => self::TOO_MANY]);
        }
        if ($command->column->backlog || $command->column->project !== $command->backlog->project) {
            throw new DomainErrors(['column' => MoveBacklogCardHandler::TARGET_IS_BACKLOG]);
        }

        $valid = array_values(array_filter($cardIds, Uuid::isValid(...)));
        $cards = $this->cards->findByIdsInProject($command->backlog->project, array_map(Uuid::fromString(...), $valid));
        $outside = array_filter($cards, static fn (Card $card): bool => $card->column !== $command->backlog);
        if (\count($cards) !== \count($cardIds) || [] !== $outside) {
            throw new DomainErrors(['ids' => RankBacklogCardHandler::NOT_IN_BACKLOG]);
        }

        // Backlog order, so the cards keep their rank among themselves at the end of the target.
        usort($cards, static fn (Card $left, Card $right): int => [$left->position, $left->createdAt] <=> [$right->position, $right->createdAt]);

        $this->em->wrapInTransaction(function () use ($cards, $command): void {
            foreach ($cards as $card) {
                ($this->updateCard)(new UpdateCardCommand(
                    card: $card,
                    actor: $command->actor,
                    column: $command->column,
                    position: CardMover::END_OF_COLUMN,
                ));
            }
        });

        return $cards;
    }
}
