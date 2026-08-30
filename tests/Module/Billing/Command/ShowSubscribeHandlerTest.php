<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\InstallationState;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Billing\Command\ShowSubscribeCommand;
use App\Module\Billing\Command\ShowSubscribeHandler;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\ActivePriceProvider;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\BillingGrants;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ShowSubscribeHandlerTest extends TestCase
{
    /** One active user against a cap of one: the gate is closed. */
    private const array CAP_CLOSED_FLAGS = ['billing.enabled' => true, 'registration.cap' => 1];

    private function user(): User
    {
        return new User(fullName: 'Viewing User', email: 'viewer@example.com', password: 'irrelevant');
    }

    /** @param array<string, bool|int|string> $flags */
    private function handler(
        BillingProfile $profile,
        array $flags = ['billing.enabled' => true],
        ?WaitlistEntryRepository $waitlistEntries = null,
    ): ShowSubscribeHandler {
        $profiles = $this->createStub(BillingProfileRepository::class);
        $profiles->method('findOneByUser')->willReturn($profile);

        $users = $this->createStub(UserRepository::class);
        $users->method('countActive')->willReturn(1);
        // A non-empty users table: the install wizard is closed, which is what
        // RegistrationGate::allowsNewAccounts() checks on top of the flag.
        $users->method('countHumans')->willReturn(1);

        return new ShowSubscribeHandler(
            $profiles,
            new ActivePriceProvider(FeatureFlags::service($flags), $this->createStub(StripeGatewayInterface::class), new ArrayAdapter(), new NullLogger()),
            new TrialProvisioner($profiles, FeatureFlags::service($flags), $this->createStub(EntityManagerInterface::class)),
            FeatureFlags::service($flags),
            new RegistrationGate(FeatureFlags::service($flags), $users, new InstallationState($users)),
            $waitlistEntries ?? $this->createStub(WaitlistEntryRepository::class),
        );
    }

    public function test_a_disabled_user_at_full_capacity_sees_the_cap_as_full(): void
    {
        $user = $this->user();
        $user->disabledAt = new \DateTimeImmutable();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));

        $view = ($this->handler($profile, self::CAP_CLOSED_FLAGS))(new ShowSubscribeCommand($user));

        self::assertTrue($view->accountDisabled);
        self::assertTrue($view->capFull);
        self::assertTrue($view->waitlistOpen);
        self::assertNull($view->inviteToken);
    }

    public function test_a_closed_instance_leaves_the_waitlist_shut_as_well(): void
    {
        $user = $this->user();
        $user->disabledAt = new \DateTimeImmutable();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));

        $flags = self::CAP_CLOSED_FLAGS + [RegistrationGate::ENABLED_FLAG => false];
        $view = ($this->handler($profile, $flags))(new ShowSubscribeCommand($user));

        self::assertTrue($view->capFull);
        self::assertFalse($view->waitlistOpen);
    }

    public function test_a_disabled_user_sees_no_full_cap_while_the_cap_has_room(): void
    {
        $user = $this->user();
        $user->disabledAt = new \DateTimeImmutable();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));

        $view = ($this->handler($profile))(new ShowSubscribeCommand($user));

        self::assertTrue($view->accountDisabled);
        self::assertFalse($view->capFull);
    }

    public function test_a_valid_matching_invite_opens_a_full_cap_and_is_passed_to_the_page(): void
    {
        $user = $this->user();
        $user->disabledAt = new \DateTimeImmutable();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));

        $entry = new WaitlistEntry('viewer@example.com');
        $token = $entry->issueInviteToken();
        $waitlistEntries = $this->createStub(WaitlistEntryRepository::class);
        $waitlistEntries->method('findOneByValidInviteToken')->willReturn($entry);

        $view = ($this->handler($profile, self::CAP_CLOSED_FLAGS, $waitlistEntries))(new ShowSubscribeCommand($user, inviteToken: $token));

        self::assertFalse($view->capFull);
        self::assertSame($token, $view->inviteToken);
    }

    public function test_an_enabled_user_is_never_cap_gated(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+3 days'));

        $view = ($this->handler($profile, self::CAP_CLOSED_FLAGS))(new ShowSubscribeCommand($user));

        self::assertFalse($view->accountDisabled);
        self::assertFalse($view->capFull);
    }

    public function test_an_unknown_invite_token_is_never_echoed_back_into_the_page(): void
    {
        $user = $this->user();
        $user->disabledAt = new \DateTimeImmutable();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));

        // The default repository stub resolves no entry: the token is unknown.
        $view = ($this->handler($profile, self::CAP_CLOSED_FLAGS))(new ShowSubscribeCommand($user, inviteToken: 'bogus-token'));

        self::assertTrue($view->capFull);
        self::assertNull($view->inviteToken);
    }

    public function test_an_invite_issued_to_a_different_email_neither_opens_the_cap_nor_reaches_the_page(): void
    {
        $user = $this->user();
        $user->disabledAt = new \DateTimeImmutable();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));

        $entry = new WaitlistEntry('someone-else@example.com');
        $token = $entry->issueInviteToken();
        $waitlistEntries = $this->createStub(WaitlistEntryRepository::class);
        $waitlistEntries->method('findOneByValidInviteToken')->willReturn($entry);

        $view = ($this->handler($profile, self::CAP_CLOSED_FLAGS, $waitlistEntries))(new ShowSubscribeCommand($user, inviteToken: $token));

        self::assertTrue($view->capFull);
        self::assertNull($view->inviteToken);
    }
}
