<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final readonly class SeedInstallFlagsHandler
{
    public function __construct(
        private FeatureFlagRepository $featureFlags,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SeedInstallFlagsCommand $command): void
    {
        $existing = $this->featureFlags->findAllIndexed();

        /** @var list<array{string, FeatureFlagType, mixed, list<string>|null}> $defaults */
        $defaults = [
            ['registration.cap', FeatureFlagType::Int, $command->registrationCap, null],
            ['billing.enabled', FeatureFlagType::Bool, $command->billingEnabled, null],
            ['billing.trial_days', FeatureFlagType::Int, $command->billingTrialDays, null],
            ['billing.stripe_price_id', FeatureFlagType::Select, $command->billingStripePriceId, []],
            ['auth.github.enabled', FeatureFlagType::Bool, $command->authGithubEnabled, null],
            ['auth.google.enabled', FeatureFlagType::Bool, $command->authGoogleEnabled, null],
        ];

        $created = 0;
        foreach ($defaults as [$name, $type, $value, $options]) {
            if (isset($existing[$name])) {
                continue;
            }
            $flag = new FeatureFlag(name: $name, type: $type, value: $value);
            $flag->options = $options;
            $this->em->persist($flag);
            ++$created;
        }

        if (0 === $created) {
            return;
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent seeding request won the race on the unique flag name;
            // the flags exist, which is all this handler guarantees.
        }
    }
}
