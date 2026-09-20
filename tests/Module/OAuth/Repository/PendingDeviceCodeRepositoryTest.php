<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Repository;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Repository\GrantRepository;
use App\Module\OAuth\Repository\PendingDeviceCodeRepository;
use App\Module\OAuth\Service\OAuthGrantAccountPurger;
use App\Tests\Support\DeviceCodeRows;
use App\Tests\Support\OAuthScenario;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PendingDeviceCodeRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private PendingDeviceCodeRepository $codes;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(Connection::class);
        $this->codes = static::getContainer()->get(PendingDeviceCodeRepository::class);
        $this->user = new OAuthScenario(static::getContainer())->createUser('owner@example.com');
    }

    public function test_it_finds_a_pending_code_with_its_client_and_scopes(): void
    {
        DeviceCodeRows::insert($this->connection, 'device-1', 'BCDFGHJK');

        $pending = $this->codes->findPending('BCDFGHJK');

        self::assertNotNull($pending);
        self::assertSame('device-1', $pending->identifier);
        self::assertSame('loupe-cli', $pending->clientId);
        self::assertSame('Loupe CLI', $pending->clientName);
        self::assertSame(['agent'], $pending->scopes);
    }

    public function test_an_expired_revoked_or_answered_code_is_not_pending(): void
    {
        DeviceCodeRows::insert($this->connection, 'expired', 'BBBBBBBB', expiry: '-1 minute');
        DeviceCodeRows::insert($this->connection, 'revoked', 'CCCCCCCC', revoked: true);
        DeviceCodeRows::insert($this->connection, 'answered', 'DDDDDDDD', userId: (string) $this->user->id);

        self::assertNull($this->codes->findPending('BBBBBBBB'));
        self::assertNull($this->codes->findPending('CCCCCCCC'));
        self::assertNull($this->codes->findPending('DDDDDDDD'));
    }

    public function test_two_pending_codes_with_one_user_code_match_neither(): void
    {
        DeviceCodeRows::insert($this->connection, 'first', 'FFFFFFFF');
        DeviceCodeRows::insert($this->connection, 'second', 'FFFFFFFF');

        self::assertNull($this->codes->findPending('FFFFFFFF'));
    }

    public function test_a_code_takes_one_answer_only(): void
    {
        DeviceCodeRows::insert($this->connection, 'device-1', 'BCDFGHJK');

        self::assertTrue($this->codes->answer('device-1', (string) $this->user->id, true));
        self::assertFalse($this->codes->answer('device-1', 'someone-else', false));

        $row = $this->connection->fetchAssociative('SELECT user_identifier, user_approved FROM oauth2_device_code');
        self::assertSame((string) $this->user->id, $row['user_identifier'] ?? null);
        self::assertTrue($row['user_approved'] ?? null);
    }

    public function test_an_expired_code_takes_no_answer(): void
    {
        DeviceCodeRows::insert($this->connection, 'device-1', 'BCDFGHJK', expiry: '-1 minute');

        self::assertFalse($this->codes->answer('device-1', (string) $this->user->id, true));
    }

    public function test_the_grant_repository_purges_revokes_and_deletes_device_codes(): void
    {
        $grants = static::getContainer()->get(GrantRepository::class);
        DeviceCodeRows::insert($this->connection, 'expired', 'BBBBBBBB', expiry: '-1 minute');
        DeviceCodeRows::insert($this->connection, 'mine', 'CCCCCCCC', userId: (string) $this->user->id);
        DeviceCodeRows::insert($this->connection, 'pending', 'DDDDDDDD');

        $grants->deleteExpired();
        self::assertSame(['mine', 'pending'], $this->identifiers());

        $grants->revokeForUserAndClient($this->user->id ?? throw new \LogicException('persisted'), 'loupe-cli');
        self::assertSame(['mine'], array_map(trim(...), $this->connection->fetchFirstColumn('SELECT identifier FROM oauth2_device_code WHERE revoked = true')));

        static::getContainer()->get(OAuthGrantAccountPurger::class)->purge($this->user, new AccountDeletionCleanup());
        self::assertSame(['pending'], $this->identifiers());
    }

    /** @return list<string> */
    private function identifiers(): array
    {
        return array_values(array_map(trim(...), $this->connection->fetchFirstColumn('SELECT identifier FROM oauth2_device_code ORDER BY identifier')));
    }
}
