<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\RegistrationGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class RegistrationGateTest extends TestCase
{
    #[DataProvider('boundaries')]
    public function test_gate_boundaries(int $cap, int $userCount, bool $expectedOpen): void
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getIntValue')->willReturn($cap);

        $users = $this->createStub(UserRepository::class);
        $users->method('countAll')->willReturn($userCount);

        self::assertSame($expectedOpen, new RegistrationGate($flags, $users)->isOpen());
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function boundaries(): iterable
    {
        yield 'no cap set (0 = unlimited)' => [0, 1_000, true];
        yield 'below cap' => [10, 9, true];
        yield 'at cap' => [10, 10, false];
        yield 'above cap' => [10, 11, false];
    }
}
