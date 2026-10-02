<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Workflow\Entity\WorkflowBinding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowBinding> */
class WorkflowBindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowBinding::class);
    }

    public function findOneByProjectId(Uuid $projectId): ?WorkflowBinding
    {
        return $this->findOneBy(['project' => $projectId]);
    }
}
