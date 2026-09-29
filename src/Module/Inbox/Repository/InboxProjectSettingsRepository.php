<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxProjectSettings;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InboxProjectSettings> */
class InboxProjectSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxProjectSettings::class);
    }

    public function findForProject(Project $project): ?InboxProjectSettings
    {
        return $this->findOneBy(['project' => $project]);
    }
}
