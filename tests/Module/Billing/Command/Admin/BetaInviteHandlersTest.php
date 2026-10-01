<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\Admin\CreateBetaInviteCommand;
use App\Module\Billing\Command\Admin\CreateBetaInviteHandler;
use App\Module\Billing\Command\Admin\RevokeBetaInviteCommand;
use App\Module\Billing\Command\Admin\RevokeBetaInviteHandler;
use App\Module\Billing\Entity\BetaInvite;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditOutcome;

final class BetaInviteHandlersTest extends KernelTestCase
{
    public function test_create_stores_the_hash_and_records_no_token(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $admin = $this->user('create-admin');

        $view = self::getContainer()->get(CreateBetaInviteHandler::class)(new CreateBetaInviteCommand($admin, 'reddit u/foo'));

        self::assertTrue($view->invite->matches($view->token));
        self::assertSame('reddit u/foo', $view->invite->note);
        self::assertSame($admin, $view->invite->createdBy);
        self::assertNotNull($view->invite->id);

        $record = $audit->record('billing.beta_invite_created');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(['betaInviteId' => (string) $view->invite->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('beta_invite', $record->subject->type);
        self::assertSame((string) $view->invite->id, $record->subject->id);
    }

    public function test_revoke_ends_an_unused_invite_and_records_it(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $invite = $this->invite($this->user('revoke-admin'));

        self::getContainer()->get(RevokeBetaInviteHandler::class)(new RevokeBetaInviteCommand($invite));

        self::assertTrue($invite->isRevoked());
        $record = $audit->record('billing.beta_invite_revoked');
        self::assertSame(['betaInviteId' => (string) $invite->id], $record->context);
    }

    public function test_revoking_a_redeemed_invite_is_a_domain_error(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $invite = $this->invite($this->user('redeemed-admin'));
        $invite->redeem($this->user('redeemed-user'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        try {
            self::getContainer()->get(RevokeBetaInviteHandler::class)(new RevokeBetaInviteCommand($invite));
            self::fail('Revoking a redeemed invite should be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['invite' => 'billing.admin.beta_invite.error.redeemed'], $e->errors);
        }

        self::assertFalse($invite->isRevoked());
        self::assertSame([], $audit->records('billing.beta_invite_revoked'));
    }

    /**
     * The lock that serializes a revoke against a redemption cannot be shown
     * here: the test runs inside one connection's transaction.
     */
    public function test_revoking_twice_is_a_domain_error(): void
    {
        self::bootKernel();
        $invite = $this->invite($this->user('twice-admin'));
        $handler = self::getContainer()->get(RevokeBetaInviteHandler::class);
        $handler(new RevokeBetaInviteCommand($invite));

        try {
            $handler(new RevokeBetaInviteCommand($invite));
            self::fail('Revoking a revoked invite should be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['invite' => 'billing.admin.beta_invite.error.revoked'], $e->errors);
        }
    }

    public function test_the_handlers_keep_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(CreateBetaInviteHandler::class);
        DirectLogging::assertRemovedFrom(RevokeBetaInviteHandler::class);
    }

    private function invite(User $createdBy): BetaInvite
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$invite] = BetaInvite::issue($createdBy, 'note');
        $em->persist($invite);
        $em->flush();

        return $invite;
    }

    private function user(string $prefix): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Test', $prefix.'@beta-handler.example.com', 'hashed-password-placeholder');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
