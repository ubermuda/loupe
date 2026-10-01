<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Registration;

use App\Module\Account\Entity\User;
use App\Module\Account\Registration\RegistrationPasses;
use App\Module\Account\Registration\RegistrationPassInterface;
use PHPUnit\Framework\TestCase;

final class RegistrationPassesTest extends TestCase
{
    public function test_no_pass_accepts_a_token_when_none_is_registered(): void
    {
        $passes = new RegistrationPasses([]);

        self::assertFalse($passes->isValid('token'));
        self::assertFalse($passes->redeem('token', $this->user()));
    }

    public function test_the_first_pass_that_accepts_wins_and_the_rest_are_not_asked(): void
    {
        $refusing = $this->createMock(RegistrationPassInterface::class);
        $refusing->expects($this->once())->method('redeem')->willReturn(false);
        $accepting = $this->createMock(RegistrationPassInterface::class);
        $accepting->expects($this->once())->method('redeem')->willReturn(true);
        $unreached = $this->createMock(RegistrationPassInterface::class);
        $unreached->expects($this->never())->method('redeem');

        self::assertTrue(new RegistrationPasses([$refusing, $accepting, $unreached])->redeem('token', $this->user()));
    }

    public function test_a_token_is_valid_when_any_pass_accepts_it(): void
    {
        $refusing = $this->createStub(RegistrationPassInterface::class);
        $refusing->method('isValid')->willReturn(false);
        $accepting = $this->createStub(RegistrationPassInterface::class);
        $accepting->method('isValid')->willReturn(true);

        self::assertTrue(new RegistrationPasses([$refusing, $accepting])->isValid('token'));
        self::assertFalse(new RegistrationPasses([$refusing])->isValid('token'));
    }

    private function user(): User
    {
        return new User(fullName: 'Riley Chen', email: 'riley@example.com');
    }
}
