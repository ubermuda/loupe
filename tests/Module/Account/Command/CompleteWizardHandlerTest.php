<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\CompleteWizardCommand;
use App\Module\Account\Command\CompleteWizardHandler;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CompleteWizardHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private LoggerInterface&MockObject $logger;
    private CompleteWizardHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new CompleteWizardHandler($this->em, $this->logger);
    }

    public function test_sets_timestamp_flushes_once_and_logs(): void
    {
        $user = new User('Wiz One', 'wiz1@example.com');

        $this->em->expects($this->once())->method('flush');
        $this->logger->expects($this->once())->method('info')
            ->with('account.wizard.completed', $this->anything());

        ($this->handler)(new CompleteWizardCommand($user));

        self::assertNotNull($user->wizardCompletedAt);
    }

    public function test_second_call_is_a_no_op(): void
    {
        $user = new User('Wiz Two', 'wiz2@example.com');
        $user->wizardCompletedAt = new \DateTimeImmutable('-1 hour');
        $first = $user->wizardCompletedAt;

        $this->em->expects($this->never())->method('flush');
        $this->logger->expects($this->never())->method('info');

        ($this->handler)(new CompleteWizardCommand($user));

        self::assertSame($first, $user->wizardCompletedAt);
    }
}
