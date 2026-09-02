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
use App\Module\Billing\Command\Admin\RevokeCompCommand;
use App\Module\Billing\Command\Admin\RevokeCompHandler;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Tests\Support\BillingGrants;
use App\Tests\Support\BillingScenario;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class RevokeCompHandlerTest extends KernelTestCase
{
    public function test_it_ends_the_comp_and_keeps_the_row(): void
    {
        self::bootKernel();
        [$admin, $target] = $this->actors('revokeone');
        $comp = static::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($target, $admin));
        $compId = $comp->id;

        $revoked = $this->handler()(new RevokeCompCommand($target, $admin));

        self::assertNotNull($revoked->endsAt);
        self::assertFalse($revoked->isCurrent(new \DateTimeImmutable('+1 second')));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(Subscription::class, $compId ?? throw new \LogicException('a flushed comp has an id'));
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertSame(SubscriptionKind::Comp, $reloaded->kind);
        self::assertNotNull($reloaded->endsAt);
    }

    public function test_revoking_without_a_comp_is_a_domain_error(): void
    {
        self::bootKernel();
        [$admin, $target] = $this->actors('revoketwo');

        try {
            $this->handler()(new RevokeCompCommand($target, $admin));
            self::fail('Revoking a comp that does not exist should be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['comp' => 'billing.admin.comp.error.not_comped'], $e->errors);
        }
    }

    public function test_a_revoked_comp_can_be_granted_again(): void
    {
        self::bootKernel();
        [$admin, $target] = $this->actors('revokethree');
        $grant = static::getContainer()->get(GrantCompHandler::class);
        $grant(new GrantCompCommand($target, $admin));
        $this->handler()(new RevokeCompCommand($target, $admin));

        $second = $grant(new GrantCompCommand($target, $admin));

        self::assertTrue($second->isCurrent(new \DateTimeImmutable()));
    }

    /**
     * The mirror of the re-enable on grant. Nothing else grants access after
     * the comp ends, so the account returns to the state the sweep left it in.
     */
    public function test_revoking_the_last_grant_disables_the_account(): void
    {
        self::bootKernel();
        $scenario = new BillingScenario(static::getContainer());
        [$admin, $target] = $this->actors('revokefour');
        $scenario->profile($target, new \DateTimeImmutable('-1 day'));
        static::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($target, $admin));

        $this->handler()(new RevokeCompCommand($target, $admin));

        self::assertNotNull($this->reload($target)->disabledAt);
    }

    /** A live Stripe subscription still grants access, so the account stays enabled. */
    public function test_revoking_leaves_a_stripe_subscriber_enabled(): void
    {
        self::bootKernel();
        $scenario = new BillingScenario(static::getContainer());
        [$admin, $target] = $this->actors('revokefive');
        $profile = $scenario->profile($target, new \DateTimeImmutable('-1 day'));
        $scenario->grant(BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('+30 days'), 'sub_revoke'));
        static::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($target, $admin));

        $this->handler()(new RevokeCompCommand($target, $admin));

        self::assertNull($this->reload($target)->disabledAt);
    }

    public function test_a_revoked_comp_is_recorded_against_the_comped_account(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$admin, $target] = $this->actors('revokeaudit');
        static::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($target, $admin));
        $audit->forget();

        $this->handler()(new RevokeCompCommand($target, $admin));

        $record = $audit->record('billing.comp.revoked');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $target->id, $record->subject->id);
        self::assertSame(['userId' => (string) $target->id], $record->context);

        self::assertSame(['billing.comp.revoked'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    /** The admin acts; the comped account is acted upon. The record must keep them apart. */
    public function test_the_admin_is_the_actor_and_never_the_subject(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$admin, $target] = $this->actors('revokeactor');
        static::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($target, $admin));
        $audit->forget();
        $this->signIn($admin);

        $this->handler()(new RevokeCompCommand($target, $admin));

        $record = $audit->record('billing.comp.revoked');
        self::assertSame($admin, $record->actor);
        self::assertSame((string) $admin->id, $record->actorIdentifier);
        self::assertSame(AuditChannel::Session->value, $record->channel);
        self::assertNotNull($record->subject);
        self::assertSame((string) $target->id, $record->subject->id);

        $context = $record->context;
        self::assertArrayNotHasKey('actorId', $context);
        self::assertNotContains((string) $admin->id, $context);
    }

    public function test_revoking_the_last_grant_records_the_disable_after_the_revocation(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $scenario = new BillingScenario(static::getContainer());
        [$admin, $target] = $this->actors('revokedisableaudit');
        $scenario->profile($target, new \DateTimeImmutable('-1 day'));
        static::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($target, $admin));
        $audit->forget();

        $this->handler()(new RevokeCompCommand($target, $admin));

        self::assertSame(
            ['billing.comp.revoked', 'billing.account.disabled_on_comp_revoke'],
            $audit->operations(),
        );
        self::assertSame(
            ['userId' => (string) $target->id],
            $audit->record('billing.account.disabled_on_comp_revoke')->context,
        );
    }

    /** A live Stripe subscription still grants access, so nothing was disabled to record. */
    public function test_revoking_for_a_stripe_subscriber_records_only_the_revocation(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $scenario = new BillingScenario(static::getContainer());
        [$admin, $target] = $this->actors('revokestripeaudit');
        $profile = $scenario->profile($target, new \DateTimeImmutable('-1 day'));
        $scenario->grant(BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('+30 days'), 'sub_revoke_audit'));
        static::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($target, $admin));
        $audit->forget();

        $this->handler()(new RevokeCompCommand($target, $admin));

        self::assertSame(['billing.comp.revoked'], $audit->operations());
    }

    public function test_a_refused_revocation_records_nothing(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$admin, $target] = $this->actors('revokerefusedaudit');

        try {
            $this->handler()(new RevokeCompCommand($target, $admin));
            self::fail('Revoking a comp that does not exist should be refused.');
        } catch (DomainErrors) {
        }

        self::assertSame([], $audit->operations());
    }

    /** No Stripe id reaches a record, even where the account has a live subscription. */
    public function test_the_record_carries_no_stripe_identifier(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $scenario = new BillingScenario(static::getContainer());
        [$admin, $target] = $this->actors('revokenostripeid');
        $profile = $scenario->profile($target, new \DateTimeImmutable('-1 day'));
        $scenario->grant(BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('+30 days'), 'sub_revoke_noid'));
        static::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($target, $admin));
        $audit->forget();

        $this->handler()(new RevokeCompCommand($target, $admin));

        $context = $audit->record('billing.comp.revoked')->context;
        self::assertSame([], array_filter(
            $context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value) && str_starts_with($value, 'sub_'),
        ));
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(RevokeCompHandler::class);
    }

    private function signIn(User $user): void
    {
        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function reload(User $user): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(User::class, $user->id ?? throw new \LogicException('a flushed user has an id'));
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
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

    private function handler(): RevokeCompHandler
    {
        return static::getContainer()->get(RevokeCompHandler::class);
    }
}
