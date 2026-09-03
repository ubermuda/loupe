<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\RequestAccountDeletionCommand;
use App\Module\Account\Command\RequestAccountDeletionHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\AccountDeletionEmailSender;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\TestCase;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\NullAuditActorProvider;

final class RequestAccountDeletionHandlerTest extends TestCase
{
    public function test_invoking_sends_the_confirmation_email_and_records_the_request(): void
    {
        $user = new User('Del Req', 'del-req@example.com', 'hash');

        $sender = $this->createMock(AccountDeletionEmailSender::class);
        $sender->expects($this->once())->method('send')->with($user);

        $audit = new RecordingAuditor(new NullAuditActorProvider());
        new RequestAccountDeletionHandler($sender, $audit->auditor)(new RequestAccountDeletionCommand($user));

        $record = $audit->record('account.deletion_requested');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['userId' => (string) $user->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $user->id, $record->subject->id);

        self::assertSame(['account.deletion_requested'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(RequestAccountDeletionHandler::class);
    }

    public function test_a_second_request_while_a_token_is_still_active_sends_no_email(): void
    {
        $user = new User('Del Req 2', 'del-req-2@example.com', 'hash');
        $user->generateAccountDeletionToken();

        $sender = $this->createMock(AccountDeletionEmailSender::class);
        $sender->expects($this->never())->method('send');

        $audit = new RecordingAuditor(new NullAuditActorProvider());
        new RequestAccountDeletionHandler($sender, $audit->auditor)(new RequestAccountDeletionCommand($user));

        self::assertSame([], $audit->operations());
    }
}
