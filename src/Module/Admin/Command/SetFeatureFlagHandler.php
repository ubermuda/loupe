<?php

declare(strict_types=1);

namespace App\Module\Admin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final readonly class SetFeatureFlagHandler
{
    public function __construct(
        private FeatureFlagRepository $featureFlags,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SetFeatureFlagCommand $command): void
    {
        $flag = $this->featureFlags->findOneBy(['name' => $command->name]);
        if (null === $flag) {
            $flag = new FeatureFlag($command->name, FeatureFlagType::Bool, $command->enabled);
            $this->em->persist($flag);
        } else {
            $flag->type = FeatureFlagType::Bool;
            $flag->value = $command->enabled;
        }
        $this->em->flush();
    }
}
