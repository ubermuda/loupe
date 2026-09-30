<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\PullRequestCommenters;
use App\Module\Forge\Service\PullRequestCommentFailed;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubPullRequestCommenter;
use App\Module\GitHub\Service\GitHubPullRequestInstallations;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubPullRequestCommenterTest extends KernelTestCase
{
    private const int NOW = 1_790_000_000;

    private EntityManagerInterface $em;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_the_registry_hands_github_pull_requests_to_this_commenter(): void
    {
        $commenter = $this->commenter();
        $commenters = new PullRequestCommenters([$commenter]);

        self::assertSame($commenter, $commenters->for('github'));
        self::assertNull($commenters->for('gitlab'));
    }

    public function test_it_posts_the_body_as_an_issue_comment_of_the_pull_request(): void
    {
        $pullRequest = $this->tracked('Ubermuda/Loupe.site', 71_001);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['id' => 1], 201),
        ];

        $this->commenter()->comment($pullRequest, 'A fix run started.');

        self::assertCount(2, $this->requests);
        self::assertSame('https://api.github.com/app/installations/71001/access_tokens', $this->requests[0]['url']);
        self::assertSame('POST', $this->requests[1]['method']);
        self::assertSame('https://api.github.com/repos/Ubermuda/Loupe.site/issues/604/comments', $this->requests[1]['url']);
        $body = $this->requests[1]['options']['body'] ?? null;
        self::assertIsString($body);
        self::assertSame(['body' => 'A fix run started.'], json_decode($body, true, flags: \JSON_THROW_ON_ERROR));
    }

    public function test_the_repository_path_is_url_encoded(): void
    {
        $pullRequest = $this->tracked('ubermuda/lou pe', 71_002);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['id' => 1], 201),
        ];

        $this->commenter()->comment($pullRequest, 'Hello');

        self::assertSame('https://api.github.com/repos/ubermuda/lou%20pe/issues/604/comments', $this->requests[1]['url']);
    }

    /** @return iterable<string, array{int}> */
    public static function refusals(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
        yield '404' => [404];
    }

    #[DataProvider('refusals')]
    public function test_a_refused_comment_is_a_permanent_permission_failure(int $status): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_010 + $status);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{"message":"Resource not accessible by integration"}', ['http_code' => $status]),
        ];

        $failure = $this->failure($pullRequest);

        self::assertSame('permission', $failure->cause);
        self::assertTrue($failure->permanent);
    }

    /** @return iterable<string, array{int, array<string, string>, ?int}> */
    public static function rateLimits(): iterable
    {
        yield 'a 403 with no requests left' => [403, ['x-ratelimit-remaining' => '0'], null];
        yield 'a 403 that asks to retry later' => [403, ['retry-after' => '60'], 60];
        yield 'a 429' => [429, [], null];
        yield 'a 403 with the reset time of the limit' => [403, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) (self::NOW + 120)], 120];
        yield 'a 429 with a reset time already past' => [429, ['x-ratelimit-reset' => (string) (self::NOW - 5)], 0];
        yield 'a 429 with both headers' => [429, ['retry-after' => '30', 'x-ratelimit-reset' => (string) (self::NOW + 120)], 30];
        yield 'a 429 with a date as retry-after' => [429, ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'], null];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('rateLimits')]
    public function test_a_rate_limit_is_a_transient_failure_that_carries_the_delay_github_asks_for(int $status, array $headers, ?int $retryAfterSeconds): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_020 + $status);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{"message":"API rate limit exceeded"}', ['http_code' => $status, 'response_headers' => $headers]),
        ];

        $failure = $this->failure($pullRequest);

        self::assertSame('api_failed_rate_limited', $failure->cause);
        self::assertFalse($failure->permanent);
        self::assertSame($retryAfterSeconds, $failure->retryAfterSeconds);
    }

    public function test_the_lookup_reads_the_comments_since_the_given_time_and_finds_the_marker(): void
    {
        $pullRequest = $this->tracked('Ubermuda/Loupe.site', 71_030);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer([['id' => 1, 'body' => 'LGTM'], ['id' => 2, 'body' => "Loupe queued a fix run.\n\n<!-- loupe-fix-run:run-1 -->"]]),
        ];

        $found = $this->commenter()->hasComment($pullRequest, '<!-- loupe-fix-run:run-1 -->', new \DateTimeImmutable('2026-09-29 11:00:00 UTC'));

        self::assertTrue($found);
        self::assertSame('GET', $this->requests[1]['method']);
        $url = parse_url($this->requests[1]['url']);
        self::assertIsArray($url);
        self::assertSame('/repos/Ubermuda/Loupe.site/issues/604/comments', $url['path'] ?? null);
        parse_str($url['query'] ?? '', $query);
        self::assertSame(['since' => '2026-09-29T11:00:00Z', 'per_page' => '100', 'page' => '1'], $query);
    }

    public function test_the_lookup_reads_the_next_page_until_it_finds_the_marker(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_032);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($this->comments(100)),
            $this->answer([['id' => 101, 'body' => '<!-- loupe-fix-run:run-1 -->']]),
        ];

        self::assertTrue($this->commenter()->hasComment($pullRequest, '<!-- loupe-fix-run:run-1 -->', new \DateTimeImmutable()));
        self::assertCount(3, $this->requests);
        self::assertSame(['1', '2'], [$this->pageOf(1), $this->pageOf(2)]);
    }

    public function test_the_lookup_stops_at_a_short_page(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_033);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($this->comments(100)),
            $this->answer($this->comments(99)),
        ];

        self::assertFalse($this->commenter()->hasComment($pullRequest, '<!-- loupe-fix-run:run-1 -->', new \DateTimeImmutable()));
        self::assertCount(3, $this->requests);
    }

    public function test_the_lookup_reads_ten_pages_at_most(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_034);
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201)];
        for ($page = 1; $page <= 11; ++$page) {
            $this->responses[] = $this->answer($this->comments(100));
        }

        self::assertFalse($this->commenter()->hasComment($pullRequest, '<!-- loupe-fix-run:run-1 -->', new \DateTimeImmutable()));
        self::assertCount(11, $this->requests);
        self::assertSame('10', $this->pageOf(10));
    }

    /** @return list<array{id: int, body: string}> */
    private function comments(int $count): array
    {
        return array_map(static fn (int $id): array => ['id' => $id, 'body' => 'LGTM'], range(1, $count));
    }

    private function pageOf(int $request): ?string
    {
        parse_str((string) parse_url($this->requests[$request]['url'], \PHP_URL_QUERY), $query);

        return \is_string($query['page'] ?? null) ? $query['page'] : null;
    }

    public function test_the_lookup_answers_false_without_the_marker(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_031);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer([['id' => 1, 'body' => '<!-- loupe-fix-run:run-2 -->'], ['id' => 2, 'body' => null]]),
        ];

        self::assertFalse($this->commenter()->hasComment($pullRequest, '<!-- loupe-fix-run:run-1 -->', new \DateTimeImmutable()));
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function lookupFailures(): iterable
    {
        yield 'a refusal' => [403, 'permission', true];
        yield 'a server error' => [502, 'api_failed_http_status_502', false];
    }

    #[DataProvider('lookupFailures')]
    public function test_a_failed_lookup_fails_like_a_post(int $status, string $cause, bool $permanent): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_040 + $status);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{"message":"No"}', ['http_code' => $status]),
        ];

        try {
            $this->commenter()->hasComment($pullRequest, '<!-- loupe-fix-run:run-1 -->', new \DateTimeImmutable());
            self::fail('Expected PullRequestCommentFailed.');
        } catch (PullRequestCommentFailed $e) {
            self::assertSame($cause, $e->cause);
            self::assertSame($permanent, $e->permanent);
        }
    }

    public function test_a_server_error_is_a_transient_failure_that_names_the_status(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_003);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{"message":"Server Error"}', ['http_code' => 502]),
        ];

        $failure = $this->failure($pullRequest);

        self::assertSame('api_failed_http_status_502', $failure->cause);
        self::assertFalse($failure->permanent);
    }

    public function test_a_missing_app_configuration_is_a_permanent_failure(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_006);

        $failure = $this->failure($pullRequest, appId: null);

        self::assertSame('api_failed_not_configured', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->requests);
    }

    public function test_an_unusable_private_key_is_a_permanent_failure(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_007);

        $failure = $this->failure($pullRequest, privateKey: 'not a key');

        self::assertSame('api_failed_bad_key', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->requests);
    }

    public function test_a_transport_error_is_a_transient_failure(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_004);
        $this->responses = [new MockResponse('', ['error' => 'Could not resolve host'])];

        $failure = $this->failure($pullRequest);

        self::assertSame('api_failed_transport', $failure->cause);
        self::assertFalse($failure->permanent);
    }

    public function test_a_repository_without_an_installation_is_a_permanent_failure(): void
    {
        $project = $this->project();
        $this->em->persist(new ForgeRepository($project, 'github', '604', 'ubermuda/loupe', ForgeRepositorySource::Hook));
        $pullRequest = new ForgePullRequest($project, 'github', 'ubermuda/loupe', 604);
        $this->em->persist($pullRequest);
        $this->em->flush();

        $failure = $this->failure($pullRequest);

        self::assertSame('no_installation', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->requests);
    }

    public function test_a_suspended_installation_is_a_permanent_failure_of_its_own(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_005);
        $installations = self::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);
        $installation = $installations->findOneByInstallationId(71_005);
        self::assertInstanceOf(GitHubInstallation::class, $installation);
        $installation->suspendedAt = new \DateTimeImmutable();
        $this->em->flush();

        $failure = $this->failure($pullRequest);

        self::assertSame('installation_suspended', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->requests);
    }

    private function tracked(string $path, int $installationId): ForgePullRequest
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation($project, $installationId, 'ubermuda', GitHubRepositorySelection::Selected));
        $this->em->persist(new ForgeRepository($project, 'github', (string) $installationId, $path, ForgeRepositorySource::Installation, (string) $installationId));
        $pullRequest = new ForgePullRequest($project, 'github', $path, 604);
        $this->em->persist($pullRequest);
        $this->em->flush();

        return $pullRequest;
    }

    private function project(): Project
    {
        $user = new User(fullName: 'Riley', email: 'commenter-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'commenter-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);

        return $project;
    }

    private function commenter(?string $appId = '123456', ?string $privateKey = null): GitHubPullRequestCommenter
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected request '.$method.' '.$url);
        }, 'https://api.github.com');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);

        $installations = self::getContainer()->get(GitHubPullRequestInstallations::class);
        self::assertInstanceOf(GitHubPullRequestInstallations::class, $installations);

        return new GitHubPullRequestCommenter(
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, $appId, $privateKey ?? $pem), new MockClock('@'.self::NOW)),
            $installations,
        );
    }

    private function failure(ForgePullRequest $pullRequest, ?string $appId = '123456', ?string $privateKey = null): PullRequestCommentFailed
    {
        try {
            $this->commenter($appId, $privateKey)->comment($pullRequest, 'Hello');
        } catch (PullRequestCommentFailed $e) {
            return $e;
        }

        self::fail('Expected PullRequestCommentFailed.');
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
