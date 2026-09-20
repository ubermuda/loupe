<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Repository;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Repository\GrantRepository;
use App\Module\OAuth\Service\ConnectedAppsExporter;
use App\Module\OAuth\Service\OAuthGrantAccountPurger;
use App\Module\Project\Entity\Project;
use App\Tests\Support\OAuthScenario;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GrantRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private OAuthScenario $scenario;
    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(Connection::class);
        $this->scenario = new OAuthScenario(static::getContainer());
        $this->scenario->createClient();
        $this->user = $this->scenario->createUser('owner@example.com');
        $this->project = $this->scenario->createProject($this->user, 'Site');
    }

    public function test_the_purge_keeps_an_expired_access_token_that_a_live_refresh_token_points_at(): void
    {
        $this->insertAccessToken('expired-with-live-refresh', '-1 hour');
        $this->insertRefreshToken('live-refresh', 'expired-with-live-refresh', '+1 month');
        $this->insertAccessToken('expired-alone', '-1 hour');
        $this->insertAccessToken('expired-with-dead-refresh', '-1 hour');
        $this->insertRefreshToken('dead-refresh', 'expired-with-dead-refresh', '-1 minute');
        $this->insertAccessToken('live', '+1 hour');

        static::getContainer()->get(GrantRepository::class)->deleteExpired();

        self::assertSame(['expired-with-live-refresh', 'live'], $this->identifiers('oauth2_access_token'));
        self::assertSame(['live-refresh'], $this->identifiers('oauth2_refresh_token'));
    }

    public function test_the_account_purger_deletes_every_row_of_the_user(): void
    {
        $other = $this->scenario->createUser('other@example.com');
        $this->insertAccessToken('mine', '+1 hour');
        $this->insertRefreshToken('my-refresh', 'mine', '+1 month');
        $this->insertAccessToken('theirs', '+1 hour', (string) $other->id, 'agent');

        $purger = static::getContainer()->get(OAuthGrantAccountPurger::class);
        self::assertGreaterThan(10, $purger->deletionOrder(), 'runs after ProjectAccountPurger');
        $purger->purge($this->user, new AccountDeletionCleanup());

        self::assertSame(['theirs'], $this->identifiers('oauth2_access_token'));
        self::assertSame([], $this->identifiers('oauth2_refresh_token'));
    }

    public function test_the_export_lists_live_grants_and_no_token_material(): void
    {
        $this->insertAccessToken('live', '+1 hour');
        $this->insertAccessToken('revoked', '+1 hour', scopes: 'agent', revoked: true);

        $rows = iterator_to_array(static::getContainer()->get(ConnectedAppsExporter::class)->export($this->user), false);

        self::assertSame([[
            'clientId' => OAuthScenario::CLIENT_ID,
            'clientName' => OAuthScenario::CLIENT_NAME,
            'scope' => 'mcp',
            'projectId' => (string) $this->project->id,
        ]], $rows);
    }

    private function insertAccessToken(string $id, string $expiry, ?string $userId = null, ?string $scopes = null, bool $revoked = false): void
    {
        $this->connection->insert('oauth2_access_token', [
            'identifier' => $id,
            'expiry' => new \DateTimeImmutable($expiry)->format('Y-m-d H:i:s'),
            'user_identifier' => $userId ?? (string) $this->user->id,
            'scopes' => $scopes ?? 'mcp project:'.$this->project->id,
            'revoked' => $revoked,
            'client' => OAuthScenario::CLIENT_ID,
        ], ['revoked' => 'boolean']);
    }

    private function insertRefreshToken(string $id, string $accessToken, string $expiry): void
    {
        $this->connection->insert('oauth2_refresh_token', [
            'identifier' => $id,
            'expiry' => new \DateTimeImmutable($expiry)->format('Y-m-d H:i:s'),
            'revoked' => false,
            'access_token' => $accessToken,
        ], ['revoked' => 'boolean']);
    }

    /** @return list<string> */
    private function identifiers(string $table): array
    {
        /* @var list<string> */
        return array_map(trim(...), $this->connection->fetchFirstColumn('SELECT identifier FROM '.$table.' ORDER BY identifier'));
    }
}
