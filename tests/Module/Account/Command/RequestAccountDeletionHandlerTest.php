<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\RequestAccountDeletionCommand;
use App\Module\Account\Command\RequestAccountDeletionHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\AccountDeletionEmailSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RequestAccountDeletionHandlerTest extends TestCase
{
    public function test_invoking_sends_the_confirmation_email(): void
    {
        $user = new User('del-req', 'Del Req', 'del-req@example.com', 'hash');

        $sender = $this->createMock(AccountDeletionEmailSender::class);
        $sender->expects($this->once())->method('send')->with($user);

        $handler = new RequestAccountDeletionHandler($sender, new NullLogger());

        $handler(new RequestAccountDeletionCommand($user));
    }
}
