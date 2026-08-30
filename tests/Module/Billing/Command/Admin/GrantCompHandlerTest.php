<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\Admin\GrantCompCommand;
use App\Module\Billing\Command\Admin\GrantCompHandler;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Tests\Support\BillingGrants;
use App\Tests\Support\BillingScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GrantCompHandlerTest extends KernelTestCase
{
    public function test_it_creates_an_open_ended_comp_recording_the_admin(): void
    {
        self::bootKernel();
        [$admin, $target] = $this->actors('grantone');

        $comp = $this->handler()(new GrantCompCommand($target, $admin));

        self::assertSame(SubscriptionKind::Comp, $comp->kind);
        self::assertNull($comp->endsAt);
        self::assertSame($admin, $comp->grantedBy);
        self::assertTrue($comp->isCurrent(new \DateTimeImmutable()));
    }

    public function test_it_provisions_a_profile_for_an_account_that_has_none(): void
    {
        self::bootKernel();
        [$admin, $target] = $this->actors('granttwo');

        $this->handler()(new GrantCompCommand($target, $admin));

        $profile = $this->profiles()->findOneByUser($target);
        self::assertNotNull($profile);
        self::assertTrue($profile->hasCurrentSubscription(new \DateTimeImmutable()));
    }

    public function test_a_second_comp_is_refused_as_a_domain_error(): void
    {
        self::bootKernel();
        [$admin, $target] = $this->actors('grantthree');
        $handler = $this->handler();
        $handler(new GrantCompCommand($target, $admin));

        try {
            $handler(new GrantCompCommand($target, $admin));
            self::fail('A second comp should be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['comp' => 'billing.admin.comp.error.already_comped'], $e->errors);
        }
    }

    /**
     * The double-billing bug the stacking model exists to prevent: a comped
     * subscriber must still be offered the portal, never a second Checkout.
     */
    public function test_a_comp_leaves_a_live_stripe_subscription_reported_as_live(): void
    {
        self::bootKernel();
        $scenario = new BillingScenario(static::getContainer());
        [$admin, $target] = $this->actors('grantfour');
        $profile = $scenario->profile($target, new \DateTimeImmutable('-1 day'));
        $scenario->grant(BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('+30 days'), 'sub_comped'));

        $this->handler()(new GrantCompCommand($target, $admin));

        self::assertTrue($profile->hasLiveSubscription());
        self::assertSame('sub_comped', $profile->latestSubscriptionOfKind(SubscriptionKind::Stripe)?->stripeSubscriptionId);
    }

    /**
     * A comp grants access, so it must also lift the disabled marker the trial
     * sweep left. Otherwise the account keeps a registration-cap spot free and
     * reads as disabled in the admin list while it has live access.
     */
    public function test_it_re_enables_an_account_the_trial_sweep_disabled(): void
    {
        self::bootKernel();
        $scenario = new BillingScenario(static::getContainer());
        [$admin, $target] = $this->actors('grantfive');
        $scenario->profile($target, new \DateTimeImmutable('-1 day'));
        $target->disabledAt = new \DateTimeImmutable('-1 hour');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();

        $this->handler()(new GrantCompCommand($target, $admin));

        $em->clear();
        $reloaded = $em->find(User::class, $target->id ?? throw new \LogicException('a flushed user has an id'));
        self::assertInstanceOf(User::class, $reloaded);
        self::assertNull($reloaded->disabledAt);
    }

    /** @return array{User, User} */
    private function actors(string $prefix): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = new User('Admin', $prefix.'-admin@example.com', 'hashed-password-placeholder');
        $admin->roles = ['ROLE_ADMIN'];
        $admin->emailVerifiedAt = new \DateTimeImmutable();

        $target = new User('Target', $prefix.'-target@example.com', 'hashed-password-placeholder');
        $target->emailVerifiedAt = new \DateTimeImmutable();

        $em->persist($admin);
        $em->persist($target);
        $em->flush();

        return [$admin, $target];
    }

    private function handler(): GrantCompHandler
    {
        return static::getContainer()->get(GrantCompHandler::class);
    }

    private function profiles(): BillingProfileRepository
    {
        return static::getContainer()->get(BillingProfileRepository::class);
    }
}
