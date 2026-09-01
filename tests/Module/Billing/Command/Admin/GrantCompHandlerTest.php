<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command\Admin;

use App\Audit\AuditChannel;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Billing\Command\Admin\GrantCompCommand;
use App\Module\Billing\Command\Admin\GrantCompHandler;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Tests\Support\BillingGrants;
use App\Tests\Support\BillingScenario;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

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

    public function test_a_granted_comp_is_recorded_against_the_comped_account(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$admin, $target] = $this->actors('grantaudit');

        $this->handler()(new GrantCompCommand($target, $admin));

        $record = $audit->record('billing.comp.granted');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $target->id, $record->subject->id);
        self::assertSame(['userId' => (string) $target->id], $record->context);

        self::assertSame(['billing.comp.granted'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    /**
     * The Billing module's one shape where somebody acts on somebody else: the
     * admin is the actor the token resolves, and the comped account is the
     * subject. Neither may stand in for the other.
     */
    public function test_the_admin_is_the_actor_and_never_the_subject(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$admin, $target] = $this->actors('grantactor');
        $this->signIn($admin);

        $this->handler()(new GrantCompCommand($target, $admin));

        $record = $audit->record('billing.comp.granted');
        self::assertSame($admin, $record->actor);
        self::assertSame((string) $admin->id, $record->actorIdentifier);
        self::assertSame(AuditChannel::Session->value, $record->channel);
        self::assertNotNull($record->subject);
        self::assertSame((string) $target->id, $record->subject->id);
        self::assertNotSame((string) $admin->id, $record->subject->id);
    }

    /** The actor is on the record itself; repeating it in the context invites the two to drift. */
    public function test_the_context_carries_no_admin_identifier(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$admin, $target] = $this->actors('grantnoadmin');
        $this->signIn($admin);

        $this->handler()(new GrantCompCommand($target, $admin));

        $context = $audit->record('billing.comp.granted')->context;
        self::assertArrayNotHasKey('actorId', $context);
        self::assertNotContains((string) $admin->id, $context);
    }

    public function test_re_enabling_a_disabled_account_records_both_operations(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $scenario = new BillingScenario(static::getContainer());
        [$admin, $target] = $this->actors('grantreenable');
        $scenario->profile($target, new \DateTimeImmutable('-1 day'));
        $target->disabledAt = new \DateTimeImmutable('-1 hour');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->handler()(new GrantCompCommand($target, $admin));

        self::assertSame(
            ['billing.account.reenabled', 'billing.comp.granted'],
            $audit->operations(),
        );
        self::assertSame(
            ['userId' => (string) $target->id],
            $audit->record('billing.account.reenabled')->context,
        );
    }

    /** An account that was never disabled has nothing to re-enable. */
    public function test_an_enabled_account_records_only_the_grant(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$admin, $target] = $this->actors('grantnoreenable');

        $this->handler()(new GrantCompCommand($target, $admin));

        self::assertSame(['billing.comp.granted'], $audit->operations());
    }

    public function test_a_refused_second_comp_records_nothing(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$admin, $target] = $this->actors('grantrefused');
        $handler = $this->handler();
        $handler(new GrantCompCommand($target, $admin));
        $audit->forget();

        try {
            $handler(new GrantCompCommand($target, $admin));
            self::fail('A second comp should be refused.');
        } catch (DomainErrors) {
        }

        self::assertSame([], $audit->operations());
    }

    /**
     * The whole log line, not only its message: the sink is what puts the
     * record back into the log stream the handler used to write to directly.
     */
    public function test_the_log_line_the_sink_emits_carries_the_record(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$admin, $target] = $this->actors('grantlogline');
        $this->signIn($admin);

        $this->handler()(new GrantCompCommand($target, $admin));

        self::assertCount(1, $audit->domainChannel->records);
        self::assertSame([
            'userId' => (string) $target->id,
            'outcome' => 'success',
            'channel' => AuditChannel::Session->value,
            'subjectType' => 'user',
            'subjectId' => (string) $target->id,
        ], $audit->domainChannel->records[0]['context']);
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(GrantCompHandler::class);
    }

    private function signIn(User $user): void
    {
        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
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
