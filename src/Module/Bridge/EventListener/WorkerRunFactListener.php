<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Service\WorkerRunFactWriter;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Uid\Uuid;

/**
 * Rewrites the fact row of each run a flush inserts or changes, and of each
 * run that gets new usage rows. Inside wrapInTransaction the rewrite joins the
 * caller's transaction.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class WorkerRunFactListener
{
    /** @var array<int, WorkerRun> by object id */
    private array $runs = [];

    private bool $writing = false;

    public function __construct(
        private readonly WorkerRunFactWriter $writer,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $entity) {
            if ($entity instanceof WorkerRun) {
                $this->runs[spl_object_id($entity)] = $entity;
            }
        }
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof WorkerRunUsage && null !== $entity->run) {
                $this->runs[spl_object_id($entity->run)] = $entity->run;
            }
        }
    }

    /** A flush during the write adds runs to the list, and the loop writes them too. */
    public function postFlush(): void
    {
        if ($this->writing) {
            return;
        }

        $this->writing = true;
        try {
            while ([] !== $this->runs) {
                $runs = $this->runs;
                $this->runs = [];
                $this->writer->upsert(array_values(array_filter(
                    array_map(static fn (WorkerRun $run): ?Uuid => $run->id, $runs),
                )));
            }
        } finally {
            $this->writing = false;
        }
    }
}
