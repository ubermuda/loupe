<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Controller\LandingController;
use App\Module\Account\Service\RegistrationGate;
use App\Service\UpdateCheck;
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
            [RegistrationGate::CAP_FLAG, FeatureFlagType::Int, $command->registrationCap, null],
            [RegistrationGate::ENABLED_FLAG, FeatureFlagType::Bool, $command->registrationEnabled, null],
            ['billing.enabled', FeatureFlagType::Bool, $command->billingEnabled, null],
            ['billing.trial_days', FeatureFlagType::Int, $command->billingTrialDays, null],
            ['billing.stripe_price_id', FeatureFlagType::Select, $command->billingStripePriceId, []],
            ['auth.github.enabled', FeatureFlagType::Bool, $command->authGithubEnabled, null],
            ['auth.google.enabled', FeatureFlagType::Bool, $command->authGoogleEnabled, null],
            // Seeded on rather than off. Its environment prerequisite already
            // holds it off on an instance with no hub configured, so seeding it
            // off would mean an operator who *did* configure Mercure still had
            // to find a switch to make it work.
            ['site_review.push.enabled', FeatureFlagType::Bool, true, null],
            // Off: it is the only outbound request the app makes on its own,
            // and an operator has to choose to tell GitHub this instance exists.
            [UpdateCheck::FLAG, FeatureFlagType::Bool, false, null],
            // Off: agent-placed highlights steer where a reviewer looks first,
            // which is a nudge an operator opts into rather than inherits.
            ['review.highlights.enabled', FeatureFlagType::Bool, false, null],
            // Off: the marketing page advertises a hosted plan with a price on
            // it, which only the instance selling that plan should serve.
            [LandingController::ENABLED_FLAG, FeatureFlagType::Bool, false, null],
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
