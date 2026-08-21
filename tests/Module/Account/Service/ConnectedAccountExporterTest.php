<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ConnectedAccountRepository;
use App\Module\Account\Service\ConnectedAccountExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class ConnectedAccountExporterTest extends TestCase
{
    public function test_exports_the_link_metadata_and_no_provider_email(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $account = new ConnectedAccount($user, SocialProvider::Google, 'google-subject-1', 'alice@gmail.com');

        $rows = new ConnectedAccountExporter($this->repositoryReturning([$account]))->export($user);

        self::assertCount(1, $rows);
        self::assertSame('google', $rows[0]['provider']);
        self::assertSame('google-subject-1', $rows[0]['providerUserId']);
        self::assertArrayHasKey('linkedAt', $rows[0]);
        $encoded = json_encode($rows, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('alice@gmail.com', $encoded);
    }

    public function test_exports_an_empty_list_when_nothing_is_linked(): void
    {
        $exporter = new ConnectedAccountExporter($this->repositoryReturning([]));

        self::assertSame([], $exporter->export(new User('Bob B', 'bob@example.com', 'x')));
        self::assertSame('connected_accounts.json', $exporter->filename());
    }

    /**
     * @param list<ConnectedAccount> $accounts
     */
    private function repositoryReturning(array $accounts): ConnectedAccountRepository
    {
        /** @var ConnectedAccountRepository&Stub $repo */
        $repo = $this->createStub(ConnectedAccountRepository::class);
        $repo->method('findBy')->willReturn($accounts);

        return $repo;
    }
}
