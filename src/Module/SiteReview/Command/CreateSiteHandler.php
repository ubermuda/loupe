<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Repository\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class CreateSiteHandler
{
    public function __construct(
        private SiteRepository $sites,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateSiteCommand $command): Site
    {
        if (null !== $this->sites->findOneByOwnerAndName($command->owner, $command->name)) {
            throw new DomainErrors(['name' => 'site_review.error.site_name_taken']);
        }

        $site = new Site($command->owner, $command->name);
        $this->em->persist($site);
        $this->em->flush();

        $this->logger->info('site_review.site.created', [
            'siteId' => (string) $site->id,
            'ownerId' => (string) $command->owner->id,
        ]);

        return $site;
    }
}
