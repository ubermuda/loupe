<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Event\BoardColumnsChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Puts the board's columns in the order given. The order must name every column
 * exactly once, so a page that missed a column added elsewhere is refused
 * rather than guessed at.
 */
final readonly class ReorderBoardColumnsHandler
{
    public const string ORDER_STALE = 'board.column.error.order_stale';

    public function __construct(
        private BoardColumnRepository $boardColumns,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(ReorderBoardColumnsCommand $command): void
    {
        $ids = array_values(array_filter(array_map(trim(...), explode(',', $command->order)), static fn (string $id): bool => '' !== $id));

        // A refusal leaves the closure as a value, for the reason in AddBoardColumnHandler.
        $order = $this->em->wrapInTransaction(function () use ($command, $ids): ?array {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

            $byId = [];
            foreach ($this->boardColumns->findForProjectFresh($command->project) as $column) {
                $byId[(string) $column->id] = $column;
            }

            $wanted = array_unique($ids);
            if ($command->expectedOrder !== implode(',', array_keys($byId))) {
                return null;
            }
            if (\count($wanted) !== \count($ids) || \count($ids) !== \count($byId) || [] !== array_diff($ids, array_keys($byId))) {
                return null;
            }

            $slugs = [];
            foreach ($ids as $position => $id) {
                $byId[$id]->position = $position;
                $slugs[] = $byId[$id]->slug;
            }
            $this->em->flush();

            return $slugs;
        });

        if (null === $order) {
            throw new DomainErrors(['order' => self::ORDER_STALE]);
        }

        $this->auditor->record(
            'board.columns_reordered',
            AuditOutcome::Success,
            ['projectId' => (string) $command->project->id, 'order' => implode(',', $order)],
            new AuditSubject('project', (string) $command->project->id),
        );
        $this->events->dispatch(new BoardColumnsChanged($command->project));
    }
}
