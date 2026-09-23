<?php

declare(strict_types=1);

namespace App\Module\Forge\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Runs inside ProjectDeleter's transaction, with no entity hydration. */
#[AsEventListener]
final readonly class DeleteForgeRepositoriesOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(
            'DELETE App\Module\Forge\Entity\ForgeRepository r WHERE r.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
