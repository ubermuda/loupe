<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\JoinWaitlistCommand;
use App\Module\Account\Command\JoinWaitlistHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class JoinWaitlistHandlerTest extends KernelTestCase
{
    public function test_creates_entry_for_new_email(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $handler);
        $repo = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);

        $handler(new JoinWaitlistCommand('new@example.com'));

        self::assertNotNull($repo->findOneByEmail('new@example.com'));
    }

    public function test_duplicate_email_is_silently_ignored(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $handler);
        $repo = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);

        $handler(new JoinWaitlistCommand('dup@example.com'));
        $handler(new JoinWaitlistCommand('DUP@example.com'));

        self::assertCount(1, $repo->findBy(['email' => 'dup@example.com']));
    }

    public function test_a_disabled_accounts_email_is_added_like_a_newcomers(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(fullName: 'Disabled Returning', email: 'disabled-returning@example.com', password: 'x');
        $user->disabledAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        $handler = $container->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $handler);
        $repo = $container->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);

        $handler(new JoinWaitlistCommand('disabled-returning@example.com'));

        self::assertNotNull($repo->findOneByEmail('disabled-returning@example.com'));
    }

    public function test_an_address_that_already_has_an_account_is_not_added(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist(new User(fullName: 'Already Registered', email: 'already-registered@example.com', password: 'x'));
        $em->flush();

        $handler = $container->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $handler);
        $repo = $container->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);

        $handler(new JoinWaitlistCommand('already-registered@example.com'));

        self::assertNull($repo->findOneByEmail('already-registered@example.com'));
    }

    public function test_a_converted_row_reopens_when_its_account_is_disabled(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $originalCreatedAt = new \DateTimeImmutable('-30 days');
        $entry = new WaitlistEntry('converted-disabled@example.com', $originalCreatedAt);
        $entry->markConverted();
        $em->persist($entry);

        $user = new User(fullName: 'Converted Disabled', email: 'converted-disabled@example.com', password: 'x');
        $user->disabledAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        $repo = $container->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $audit = new RecordingAuditor($this->actorProvider());
        $handler = new JoinWaitlistHandler($repo, $users, $em, $audit->auditor);

        $handler(new JoinWaitlistCommand('converted-disabled@example.com'));

        $reopened = $repo->findOneByEmail('converted-disabled@example.com');
        self::assertNotNull($reopened);
        self::assertNull($reopened->convertedAt);
        self::assertTrue($reopened->needsInvite());
        self::assertGreaterThan($originalCreatedAt, $reopened->createdAt);

        $record = $audit->record('account.waitlist_rejoined');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['entryId' => (string) $reopened->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('waitlist_entry', $record->subject->type);
        self::assertSame((string) $reopened->id, $record->subject->id);

        self::assertSame(['account.waitlist_rejoined'], $audit->domainLogLines());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(JoinWaitlistHandler::class);
    }

    public function test_a_new_entry_is_recorded_against_the_waitlist_row(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $repo = $container->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $audit = new RecordingAuditor($this->actorProvider());
        $handler = new JoinWaitlistHandler($repo, $users, $em, $audit->auditor);

        $handler(new JoinWaitlistCommand('recorded-join@example.com'));

        $entry = $repo->findOneByEmail('recorded-join@example.com');
        self::assertNotNull($entry);

        $record = $audit->record('account.waitlist_joined');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['entryId' => (string) $entry->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('waitlist_entry', $record->subject->type);
        self::assertSame((string) $entry->id, $record->subject->id);

        self::assertSame(['account.waitlist_joined'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    /**
     * Both branches accept the join and move no state, so both are Unchanged
     * rather than refusals a reader would count as denials.
     */
    public function test_the_duplicate_and_existing_account_branches_record_no_change(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist(new User(fullName: 'Diag', email: 'diag-existing@example.com', password: 'x'));
        $em->flush();
        $repo = $container->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $audit = new RecordingAuditor($this->actorProvider());
        $handler = new JoinWaitlistHandler($repo, $users, $em, $audit->auditor);

        $handler(new JoinWaitlistCommand('diag-dupe@example.com'));
        $entry = $repo->findOneByEmail('diag-dupe@example.com');
        self::assertNotNull($entry);
        $audit->forget();

        $handler(new JoinWaitlistCommand('diag-dupe@example.com'));
        $handler(new JoinWaitlistCommand('diag-existing@example.com'));

        self::assertSame(
            ['account.waitlist_duplicate_join', 'account.waitlist_join_skipped_existing_account'],
            $audit->operations(),
        );

        $duplicate = $audit->record('account.waitlist_duplicate_join');
        self::assertSame(AuditOutcome::Unchanged, $duplicate->outcome);
        self::assertSame(['entryId' => (string) $entry->id], $duplicate->context);
        self::assertNotNull($duplicate->subject);
        self::assertSame('waitlist_entry', $duplicate->subject->type);

        $existing = $users->findOneByEmail('diag-existing@example.com');
        self::assertNotNull($existing);
        $skipped = $audit->record('account.waitlist_join_skipped_existing_account');
        self::assertSame(AuditOutcome::Unchanged, $skipped->outcome);
        self::assertSame(['userId' => (string) $existing->id], $skipped->context);
        self::assertNotNull($skipped->subject);
        self::assertSame('user', $skipped->subject->type);
    }

    public function test_no_log_line_carries_a_raw_email_address(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist(new User(fullName: 'Registered', email: 'registered@example.com', password: 'x'));
        $em->flush();

        $repo = $container->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $audit = new RecordingAuditor($this->actorProvider());
        $handler = new JoinWaitlistHandler($repo, $users, $em, $audit->auditor);

        $handler(new JoinWaitlistCommand('fresh@example.com'));
        $handler(new JoinWaitlistCommand('fresh@example.com'));
        $handler(new JoinWaitlistCommand('registered@example.com'));

        self::assertCount(3, $audit->sink->events);
        $encoded = json_encode(
            [$audit->sink->events, $audit->domainChannel->records],
            \JSON_THROW_ON_ERROR,
        );
        self::assertStringNotContainsString('fresh@example.com', $encoded);
        self::assertStringNotContainsString('registered@example.com', $encoded);
        self::assertStringNotContainsString('@example.com', $encoded);

        $entry = $repo->findOneByEmail('fresh@example.com');
        self::assertNotNull($entry);
        self::assertSame(['entryId' => (string) $entry->id], $audit->record('account.waitlist_joined')->context);
        self::assertSame(['entryId' => (string) $entry->id], $audit->record('account.waitlist_duplicate_join')->context);
        // Named by the account rather than a digest of the address: a bare hash
        // correlates just as well while staying guessable from a wordlist.
        $registered = $users->findOneByEmail('registered@example.com');
        self::assertNotNull($registered);
        self::assertSame(
            ['userId' => (string) $registered->id],
            $audit->record('account.waitlist_join_skipped_existing_account')->context,
        );
    }

    private function actorProvider(): AuditActorProviderInterface
    {
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);

        return $actors;
    }

    public function test_a_converted_row_stays_untouched_when_its_account_is_enabled(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $entry = new WaitlistEntry('converted-enabled@example.com');
        $entry->markConverted();
        $em->persist($entry);
        $em->persist(new User(fullName: 'Converted Enabled', email: 'converted-enabled@example.com', password: 'x'));
        $em->flush();

        $handler = $container->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $handler);

        $handler(new JoinWaitlistCommand('converted-enabled@example.com'));

        $repo = $container->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);
        $fetched = $repo->findOneByEmail('converted-enabled@example.com');
        self::assertNotNull($fetched);
        self::assertNotNull($fetched->convertedAt);
        self::assertFalse($fetched->needsInvite());
    }

    public function test_a_pending_row_is_not_reset_by_a_disabled_accounts_rejoin(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $originalCreatedAt = new \DateTimeImmutable('-30 days');
        $em->persist(new WaitlistEntry('pending-disabled@example.com', $originalCreatedAt));
        $user = new User(fullName: 'Pending Disabled', email: 'pending-disabled@example.com', password: 'x');
        $user->disabledAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        $handler = $container->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $handler);

        $handler(new JoinWaitlistCommand('pending-disabled@example.com'));

        $repo = $container->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);
        $fetched = $repo->findOneByEmail('pending-disabled@example.com');
        self::assertNotNull($fetched);
        self::assertEquals($originalCreatedAt, $fetched->createdAt);
        self::assertCount(1, $repo->findBy(['email' => 'pending-disabled@example.com']));
    }

    public function test_a_converted_row_without_an_account_does_not_resurrect(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $entry = new WaitlistEntry('converted-gone@example.com');
        $entry->markConverted();
        $em->persist($entry);
        $em->flush();

        $handler = $container->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $handler);

        $handler(new JoinWaitlistCommand('converted-gone@example.com'));

        $repo = $container->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);
        $fetched = $repo->findOneByEmail('converted-gone@example.com');
        self::assertNotNull($fetched);
        self::assertNotNull($fetched->convertedAt);
        self::assertFalse($fetched->needsInvite());
    }
}
