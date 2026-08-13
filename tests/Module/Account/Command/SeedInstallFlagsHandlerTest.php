<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Command\SeedInstallFlagsHandler;
use App\Module\Account\Service\RegistrationGate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class SeedInstallFlagsHandlerTest extends KernelTestCase
{
    public function test_seeds_all_shipped_flags_with_submitted_values(): void
    {
        $handler = self::getContainer()->get(SeedInstallFlagsHandler::class);
        $flags = self::getContainer()->get(FeatureFlagRepository::class);

        $handler(new SeedInstallFlagsCommand(
            registrationCap: 50,
            registrationEnabled: true,
            billingEnabled: false,
            billingTrialDays: 14,
            billingStripePriceId: null,
            authGithubEnabled: true,
            authGoogleEnabled: false,
        ));

        $indexed = $flags->findAllIndexed();
        self::assertSame(50, $indexed['registration.cap']->value);
        self::assertSame(FeatureFlagType::Int, $indexed['registration.cap']->type);
        self::assertTrue($indexed[RegistrationGate::ENABLED_FLAG]->value);
        self::assertSame(FeatureFlagType::Bool, $indexed[RegistrationGate::ENABLED_FLAG]->type);
        self::assertFalse($indexed['billing.enabled']->value);
        self::assertSame(14, $indexed['billing.trial_days']->value);
        self::assertNull($indexed['billing.stripe_price_id']->value);
        self::assertSame(FeatureFlagType::Select, $indexed['billing.stripe_price_id']->type);
        self::assertSame([], $indexed['billing.stripe_price_id']->options);
        self::assertTrue($indexed['auth.github.enabled']->value);
        self::assertFalse($indexed['auth.google.enabled']->value);
    }

    public function test_existing_flag_is_left_untouched(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $existing = new FeatureFlag(name: 'registration.cap', type: FeatureFlagType::Int, value: 999);
        $em->persist($existing);
        $em->flush();

        $handler = self::getContainer()->get(SeedInstallFlagsHandler::class);
        $handler(new SeedInstallFlagsCommand(50, true, false, 14, null, false, false));

        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertSame(999, $flags->findAllIndexed()['registration.cap']->value);
    }

    public function test_running_twice_creates_no_duplicates(): void
    {
        $handler = self::getContainer()->get(SeedInstallFlagsHandler::class);
        $command = new SeedInstallFlagsCommand(0, true, false, 14, null, false, false);
        $handler($command);
        $handler($command);

        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertCount(11, $flags->findAll());
    }
}
