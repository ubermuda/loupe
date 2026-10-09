<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\GitHub\Entity\GitHubUserConnection;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubUserApi;
use App\Module\GitHub\Service\GitHubUserConnectionAccountPurger;
use App\Module\GitHub\Service\GitHubUserConnectionExporter;
use App\Module\GitHub\Service\GitHubUserTokenRefresher;
use App\Module\GitHub\Service\GitHubUserTokenStatus;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubUserConnectionTest extends KernelTestCase
{
    private const string NOW = '2026-10-08 12:00:00';

    /** @var list<array<int|string, string>> */
    private array $refreshRequests = [];

    private RecordingLogger $logger;

    public function test_the_tokens_are_encrypted_at_rest_and_read_back(): void
    {
        $connection = $this->connection('stored');

        $raw = $this->em()->getConnection()->fetchAssociative('SELECT access_token, refresh_token FROM github_user_connections WHERE id = :id', ['id' => (string) $connection->id]);
        self::assertIsArray($raw);
        self::assertStringNotContainsString('old-access', (string) $raw['access_token']);
        self::assertStringNotContainsString('old-refresh', (string) $raw['refresh_token']);
        $this->em()->clear();
        $reloaded = $this->em()->find(GitHubUserConnection::class, $connection->id);
        self::assertNotNull($reloaded);
        self::assertSame('old-access', $reloaded->accessToken);
        self::assertSame('old-refresh', $reloaded->refreshToken);
    }

    public function test_a_user_has_at_most_one_connection(): void
    {
        $connection = $this->connection('unique');

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->persist(new GitHubUserConnection($connection->user, 2, 'again', 'a', 'r', new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable('+1 day')));
    }

    public function test_a_live_token_is_returned_without_a_call(): void
    {
        $connection = $this->connection('live', accessExpires: '+3 hours');

        $result = $this->refresher([])->accessTokenFor($connection->user);

        self::assertSame(GitHubUserTokenStatus::Fresh, $result->status);
        self::assertSame('old-access', $result->accessToken);
        self::assertSame([], $this->refreshRequests);
    }

    public function test_a_user_without_a_connection_is_not_connected(): void
    {
        self::bootKernel();
        $user = $this->user('nobody');

        self::assertSame(GitHubUserTokenStatus::NotConnected, $this->refresher([])->accessTokenFor($user)->status);
    }

    public function test_a_token_near_its_end_is_refreshed_and_the_new_pair_is_stored(): void
    {
        $connection = $this->connection('near', accessExpires: '+60 seconds');

        $result = $this->refresher([$this->tokenResponse('new-access', 'new-refresh')])->accessTokenFor($connection->user);

        self::assertSame(GitHubUserTokenStatus::Fresh, $result->status);
        self::assertSame('new-access', $result->accessToken);
        self::assertSame('old-refresh', $this->refreshRequests[0]['refresh_token']);
        $this->em()->clear();
        $stored = $this->em()->find(GitHubUserConnection::class, $connection->id);
        self::assertNotNull($stored);
        self::assertSame('new-access', $stored->accessToken);
        self::assertSame('new-refresh', $stored->refreshToken);
        self::assertEquals(new \DateTimeImmutable('2026-10-08 20:00:00'), $stored->accessTokenExpiresAt);
        self::assertNull($stored->expiredAt);
    }

    public function test_two_calls_spend_the_refresh_token_once(): void
    {
        $connection = $this->connection('twice', accessExpires: '-1 hour');
        $refresher = $this->refresher([$this->tokenResponse('new-access', 'new-refresh')]);

        $first = $refresher->accessTokenFor($connection->user);
        $second = $refresher->accessTokenFor($connection->user);

        self::assertSame('new-access', $first->accessToken);
        self::assertSame('new-access', $second->accessToken);
        self::assertCount(1, $this->refreshRequests);
    }

    public function test_a_bad_refresh_token_marks_the_connection_expired(): void
    {
        $connection = $this->connection('bad', accessExpires: '-1 hour');
        $refresher = $this->refresher([$this->json(['error' => 'bad_refresh_token'])]);

        self::assertSame(GitHubUserTokenStatus::Expired, $refresher->accessTokenFor($connection->user)->status);

        $this->em()->clear();
        $stored = $this->em()->find(GitHubUserConnection::class, $connection->id);
        self::assertNotNull($stored);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $stored->expiredAt);
        self::assertSame('old-refresh', $stored->refreshToken);

        self::assertSame(GitHubUserTokenStatus::Expired, $refresher->accessTokenFor($stored->user)->status);
        self::assertCount(1, $this->refreshRequests, 'An expired connection makes no further call.');
    }

    public function test_an_ended_refresh_token_expires_the_connection_without_a_call(): void
    {
        $connection = $this->connection('ended', accessExpires: '-1 hour', refreshExpires: '-1 minute');

        self::assertSame(GitHubUserTokenStatus::Expired, $this->refresher([])->accessTokenFor($connection->user)->status);
        self::assertSame([], $this->refreshRequests);
    }

    public function test_a_transport_failure_leaves_the_row_alone(): void
    {
        $connection = $this->connection('blip', accessExpires: '-1 hour');

        $result = $this->refresher([new MockResponse('{}', ['http_code' => 502])])->accessTokenFor($connection->user);

        self::assertSame(GitHubUserTokenStatus::Unavailable, $result->status);
        self::assertNull($result->accessToken);
        $this->em()->clear();
        $stored = $this->em()->find(GitHubUserConnection::class, $connection->id);
        self::assertNotNull($stored);
        self::assertNull($stored->expiredAt);
        self::assertSame('old-refresh', $stored->refreshToken);
        self::assertSame(['github.user_token_refresh_failed'], array_column($this->logger->records, 'message'));
    }

    public function test_an_answer_with_no_new_refresh_token_expires_the_connection(): void
    {
        $connection = $this->connection('nopair', accessExpires: '-1 hour');

        $result = $this->refresher([$this->json(['access_token' => 'lonely'])])->accessTokenFor($connection->user);

        self::assertSame(GitHubUserTokenStatus::Expired, $result->status);
    }

    public function test_the_summary_and_the_export_hold_no_token_and_survive_an_unreadable_key(): void
    {
        $connection = $this->connection('export');
        $this->em()->getConnection()->executeStatement('UPDATE github_user_connections SET access_token = :t, refresh_token = :t WHERE id = :id', ['t' => base64_encode(random_bytes(64)), 'id' => (string) $connection->id]);
        $this->em()->clear();
        $exporter = self::getContainer()->get(GitHubUserConnectionExporter::class);
        self::assertInstanceOf(GitHubUserConnectionExporter::class, $exporter);

        $rows = [...$exporter->export($connection->user)];

        self::assertSame('github_user_connection.json', $exporter->filename());
        self::assertSame([[
            'login' => 'octocat-export',
            'connectedAt' => '2026-10-01T09:00:00+00:00',
            'expiredAt' => null,
            'accessTokenExpiresAt' => '2026-10-08T14:00:00+00:00',
            'refreshTokenExpiresAt' => '2027-04-08T12:00:00+00:00',
        ]], $rows);
        self::assertStringNotContainsString('old-', json_encode($rows, \JSON_THROW_ON_ERROR));
    }

    public function test_the_export_of_a_user_without_a_connection_is_empty(): void
    {
        self::bootKernel();
        $exporter = self::getContainer()->get(GitHubUserConnectionExporter::class);
        self::assertInstanceOf(GitHubUserConnectionExporter::class, $exporter);

        self::assertSame([], [...$exporter->export($this->user('empty'))]);
    }

    public function test_the_purger_deletes_only_the_user_connection_and_runs_after_the_project_purger(): void
    {
        $mine = $this->connection('purge-mine');
        $theirs = $this->connection('purge-theirs');
        $purger = self::getContainer()->get(GitHubUserConnectionAccountPurger::class);
        self::assertInstanceOf(GitHubUserConnectionAccountPurger::class, $purger);
        $projectPurger = self::getContainer()->get(ProjectAccountPurger::class);
        self::assertInstanceOf(ProjectAccountPurger::class, $projectPurger);
        self::assertGreaterThan($projectPurger->deletionOrder(), $purger->deletionOrder());

        $purger->purge($mine->user, new AccountDeletionCleanup());

        $this->em()->clear();
        self::assertNull($this->em()->find(GitHubUserConnection::class, $mine->id));
        self::assertNotNull($this->em()->find(GitHubUserConnection::class, $theirs->id));
    }

    public function test_deleting_the_user_deletes_the_connection(): void
    {
        $connection = $this->connection('cascade');
        $id = $connection->id;
        $this->em()->getConnection()->executeStatement('DELETE FROM users WHERE id = :id', ['id' => (string) $connection->user->id]);

        $this->em()->clear();
        self::assertNull($this->em()->find(GitHubUserConnection::class, $id));
    }

    /** @param list<MockResponse> $responses */
    private function refresher(array $responses): GitHubUserTokenRefresher
    {
        $this->logger = new RecordingLogger();
        $clock = new MockClock(self::NOW);
        $api = new GitHubUserApi(
            new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
                parse_str((string) ($options['body'] ?? ''), $body);
                $this->refreshRequests[] = array_map(static fn (mixed $v): string => \is_string($v) ? $v : '', $body);

                return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
            }, 'https://github.com'),
            new MockHttpClient(),
            new GitHubAppConfiguration('loupe-test', 'the-client-id', 'the-client-secret', 'hook', null, null),
            $clock,
        );
        $connections = self::getContainer()->get(GitHubUserConnectionRepository::class);
        self::assertInstanceOf(GitHubUserConnectionRepository::class, $connections);

        return new GitHubUserTokenRefresher($connections, $api, $this->em(), $clock, $this->logger);
    }

    private function tokenResponse(string $access, string $refresh): MockResponse
    {
        return $this->json(['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => 28800, 'refresh_token_expires_in' => 15897600]);
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
    }

    private function connection(string $label, string $accessExpires = '2026-10-08 14:00:00', string $refreshExpires = '2027-04-08 12:00:00'): GitHubUserConnection
    {
        self::bootKernel();
        $isFixed = !str_starts_with($accessExpires, '+') && !str_starts_with($accessExpires, '-');
        $now = new \DateTimeImmutable(self::NOW);
        $connection = new GitHubUserConnection(
            $this->user($label),
            random_int(1, 1_000_000),
            'octocat-'.$label,
            'old-access',
            'old-refresh',
            $isFixed ? new \DateTimeImmutable($accessExpires) : $now->modify($accessExpires),
            str_starts_with($refreshExpires, '-') ? $now->modify($refreshExpires) : new \DateTimeImmutable($refreshExpires),
            new \DateTimeImmutable('2026-10-01 09:00:00'),
        );
        $this->persist($connection);

        return $connection;
    }

    private function user(string $label): User
    {
        $user = new User(fullName: 'Riley', email: 'github-user-'.$label.'-'.uniqid().'@example.com', password: 'hashed');
        $this->persist($user);

        return $user;
    }

    private function persist(object $entity): void
    {
        $this->em()->persist($entity);
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
