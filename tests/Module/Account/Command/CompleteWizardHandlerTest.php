<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\CompleteWizardCommand;
use App\Module\Account\Command\CompleteWizardHandler;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\NullAuditActorProvider;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CompleteWizardHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private RecordingAuditor $audit;

    public function test_sets_timestamp_flushes_once_and_records(): void
    {
        $handler = $this->handler();
        $user = new User('Wiz One', 'wiz1@example.com');

        $this->em->expects($this->once())->method('flush');

        $handler(new CompleteWizardCommand($user));

        self::assertNotNull($user->wizardCompletedAt);

        $record = $this->audit->record('account.wizard.completed');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['userId' => (string) $user->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $user->id, $record->subject->id);

        self::assertSame(['account.wizard.completed'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(CompleteWizardHandler::class);
    }

    public function test_second_call_is_a_no_op(): void
    {
        $handler = $this->handler();
        $user = new User('Wiz Two', 'wiz2@example.com');
        $user->wizardCompletedAt = new \DateTimeImmutable('-1 hour');
        $first = $user->wizardCompletedAt;

        $this->em->expects($this->never())->method('flush');

        $handler(new CompleteWizardCommand($user));

        self::assertSame($first, $user->wizardCompletedAt);
        self::assertSame([], $this->audit->operations());
    }

    private function handler(): CompleteWizardHandler
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());

        return new CompleteWizardHandler($this->em, $this->audit->auditor);
    }
}
