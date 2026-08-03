<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\InstallationState;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class InstallationStateTest extends TestCase
{
    public function test_open_when_no_users_exist(): void
    {
        self::assertTrue($this->stateWithUserCount(0)->isOpen());
    }

    public function test_closed_once_a_user_exists(): void
    {
        self::assertFalse($this->stateWithUserCount(1)->isOpen());
    }

    private function stateWithUserCount(int $count): InstallationState
    {
        /** @var UserRepository&Stub $users */
        $users = $this->createStub(UserRepository::class);
        $users->method('countHumans')->willReturn($count);

        return new InstallationState($users);
    }
}
