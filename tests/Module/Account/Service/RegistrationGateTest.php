<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\InstallationState;
use App\Module\Account\Service\RegistrationGate;
use App\Tests\Support\FeatureFlags;
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
        $users->method('countActive')->willReturn($userCount);

        self::assertSame($expectedOpen, $this->gate($flags, $users)->isOpen());
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function boundaries(): iterable
    {
        yield 'no cap set (0 = unlimited)' => [0, 1_000, true];
        yield 'below cap' => [10, 9, true];
        yield 'at cap' => [10, 10, false];
        yield 'above cap' => [10, 11, false];
    }

    /** @param array<string, bool|int|string> $flags */
    #[DataProvider('newAccountConditions')]
    public function test_new_accounts_need_both_the_switch_and_a_completed_install(
        array $flags,
        int $totalUsers,
        bool $expected,
    ): void {
        $users = $this->createStub(UserRepository::class);
        $users->method('count')->willReturn($totalUsers);

        self::assertSame($expected, $this->gate(FeatureFlags::service($flags), $users)->allowsNewAccounts());
    }

    /** @return iterable<string, array{array<string, bool|int|string>, int, bool}> */
    public static function newAccountConditions(): iterable
    {
        // An instance upgraded from a version without the flag has no row for
        // it, and must keep accepting registrations exactly as it did before.
        yield 'flag absent on an installed instance' => [[], 1, true];
        yield 'flag on, installed' => [[RegistrationGate::ENABLED_FLAG => true], 1, true];
        yield 'flag off, installed' => [[RegistrationGate::ENABLED_FLAG => false], 1, false];

        // An empty users table means the install wizard is still open, and
        // whoever registers first would close it leaving no administrator.
        yield 'flag absent, install pending' => [[], 0, false];
        yield 'flag on, install pending' => [[RegistrationGate::ENABLED_FLAG => true], 0, false];
    }

    private function gate(FeatureFlagService $flags, UserRepository $users): RegistrationGate
    {
        return new RegistrationGate($flags, $users, new InstallationState($users));
    }
}
