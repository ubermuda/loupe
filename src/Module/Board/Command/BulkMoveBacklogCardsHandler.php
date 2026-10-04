<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Security\CardVoter;
use App\Module\Board\Service\CardMover;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Moves the cards ticked on one page of the Backlog to one column, all or none.
 *
 * The batch holds the project lock, which every card write takes, and makes
 * every refusal it can before the first write. Each move runs through
 * UpdateCardHandler, whose transaction nests as a savepoint in this one, so
 * a later refusal still rolls every move back, with its audit records kept.
 */
final readonly class BulkMoveBacklogCardsHandler
{
    /** One page of the Backlog page. */
    public const int MAX_CARDS = ListBacklogCardsHandler::PER_PAGE;

    public const string NONE_CHOSEN = 'board.backlog.error.none_chosen';
    public const string TOO_MANY = 'board.backlog.error.too_many';

    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private UpdateCardHandler $updateCard,
        private EntityManagerInterface $em,
        private AuthorizationCheckerInterface $authorization,
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
        foreach ($cards as $card) {
            if (!$this->authorization->isGranted(CardVoter::WRITE, $card)) {
                throw new AccessDeniedException();
            }
        }
        $outside = array_filter($cards, static fn (Card $card): bool => $card->column !== $command->backlog);
        if (\count($cards) !== \count($cardIds) || [] !== $outside) {
            throw new DomainErrors(['ids' => MoveBacklogCardHandler::NOT_IN_BACKLOG]);
        }

        return $this->em->wrapInTransaction(function () use ($cards, $command): array {
            // One lock for the batch, so no other request changes a card between the checks and the moves.
            $this->em->lock($command->backlog->project, LockMode::PESSIMISTIC_WRITE);
            // The terminal flag as the lock sees it, since it decides the move order.
            $this->boardColumns->findForProjectFresh($command->backlog->project);
            foreach ($cards as $card) {
                $this->cards->refreshColumn($card);
                if ($card->column !== $command->backlog) {
                    throw new DomainErrors(['column' => UpdateCardHandler::COLUMN_CHANGED]);
                }
                $this->cards->refreshPosition($card);
            }
            // Backlog order as the lock sees it, so the cards keep their rank among themselves at the end of the target.
            usort($cards, static fn (Card $left, Card $right): int => [$left->position, $left->createdAt] <=> [$right->position, $right->createdAt]);
            $moveOrder = $command->column->terminal ? $this->childrenFirst($cards) : $cards;
            foreach ($moveOrder as $card) {
                if ($card->column === $command->column) {
                    continue;
                }
                ($this->updateCard)(new UpdateCardCommand(
                    card: $card,
                    actor: $command->actor,
                    column: $command->column,
                    position: CardMover::END_OF_COLUMN,
                    expectedColumn: $card->column,
                ));
            }

            return $cards;
        });
    }

    /**
     * The same refusal UpdateCardHandler makes for a terminal column, made
     * before any write. A child that moves with its epic does not count, and
     * a terminal column keeps no rank, so the epics move last.
     *
     * @param list<Card> $cards
     *
     * @return list<Card>
     */
    private function childrenFirst(array $cards): array
    {
        $moving = array_map(static fn (Card $card): int => $card->number, $cards);
        $children = [];
        $epics = [];
        foreach ($cards as $card) {
            $allChildren = $this->cards->openChildNumbers($card);
            $open = array_values(array_diff($allChildren, $moving));
            if ([] !== $open) {
                throw new EpicChildrenOpen($open);
            }
            if ([] === $allChildren) {
                $children[] = $card;
            } else {
                $epics[] = $card;
            }
        }

        return [...$children, ...$epics];
    }
}
