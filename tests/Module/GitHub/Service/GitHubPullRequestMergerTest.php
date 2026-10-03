<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\PullRequestMergers;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubPullRequestInstallations;
use App\Module\GitHub\Service\GitHubPullRequestMerger;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubPullRequestMergerTest extends KernelTestCase
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

    public function test_the_registry_hands_github_pull_requests_to_this_merger(): void
    {
        $merger = $this->merger();
        $mergers = new PullRequestMergers([$merger]);

        self::assertSame($merger, $mergers->for('github'));
        self::assertNull($mergers->for('gitlab'));
    }

    /** @return iterable<string, array{string}> */
    public static function methods(): iterable
    {
        yield 'merge' => ['merge'];
        yield 'squash' => ['squash'];
        yield 'rebase' => ['rebase'];
    }

    #[DataProvider('methods')]
    public function test_it_asks_github_to_merge_the_expected_head_with_the_method(string $method): void
    {
        $pullRequest = $this->tracked('Ubermuda/Loupe.site', 73_001);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['sha' => 'merge1', 'merged' => true, 'message' => 'Pull Request successfully merged']),
        ];

        $this->merger()->merge($pullRequest, $method, 'head1');

        self::assertCount(2, $this->requests);
        self::assertSame('https://api.github.com/app/installations/73001/access_tokens', $this->requests[0]['url']);
        self::assertSame('PUT', $this->requests[1]['method']);
        self::assertSame('https://api.github.com/repos/Ubermuda/Loupe.site/pulls/604/merge', $this->requests[1]['url']);
        $body = $this->requests[1]['options']['body'] ?? null;
        self::assertIsString($body);
        self::assertSame(['merge_method' => $method, 'sha' => 'head1'], json_decode($body, true, flags: \JSON_THROW_ON_ERROR));
    }

    public function test_an_unknown_method_is_refused_before_any_request(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 73_002);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->merger()->merge($pullRequest, 'fast-forward', 'head1');
        } finally {
            self::assertSame([], $this->requests);
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function refusals(): iterable
    {
        yield '401' => [401, 'permission'];
        yield '403' => [403, 'permission'];
        yield '404' => [404, 'permission'];
        yield '405 not mergeable' => [405, 'refused'];
        yield '422 unprocessable' => [422, 'refused'];
        yield '409 head moved' => [409, 'head_moved'];
    }

    #[DataProvider('refusals')]
    public function test_a_refused_merge_is_a_permanent_failure_with_its_cause(int $status, string $cause): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 73_010 + $status);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{"message":"No"}', ['http_code' => $status]),
        ];

        $failure = $this->failure($pullRequest);

        self::assertSame($cause, $failure->cause);
        self::assertTrue($failure->permanent);
    }

    /** @return iterable<string, array{int, array<string, string>, ?int}> */
    public static function rateLimits(): iterable
    {
        yield 'a 403 with no requests left' => [403, ['x-ratelimit-remaining' => '0'], null];
        yield 'a 403 that asks to retry later' => [403, ['retry-after' => '60'], 60];
        yield 'a 429 with the reset time of the limit' => [429, ['x-ratelimit-reset' => (string) (self::NOW + 120)], 120];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('rateLimits')]
    public function test_a_rate_limit_is_a_transient_failure_that_carries_the_delay_github_asks_for(int $status, array $headers, ?int $retryAfterSeconds): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 73_020 + $status);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{"message":"API rate limit exceeded"}', ['http_code' => $status, 'response_headers' => $headers]),
        ];

        $failure = $this->failure($pullRequest);

        self::assertSame('api_failed_rate_limited', $failure->cause);
        self::assertFalse($failure->permanent);
        self::assertSame($retryAfterSeconds, $failure->retryAfterSeconds);
    }

    public function test_a_server_error_is_a_transient_failure_that_names_the_status(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 73_003);
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
        $pullRequest = $this->tracked('ubermuda/loupe', 73_004);

        $failure = $this->failure($pullRequest, appId: null);

        self::assertSame('api_failed_not_configured', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->requests);
    }

    public function test_a_transport_error_is_a_transient_failure(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 73_005);
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
        $pullRequest = $this->tracked('ubermuda/loupe', 73_006);
        $installations = self::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);
        $installation = $installations->findOneByInstallationId(73_006);
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
        $user = new User(fullName: 'Riley', email: 'merger-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'merger-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);

        return $project;
    }

    private function merger(?string $appId = '123456'): GitHubPullRequestMerger
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

        return new GitHubPullRequestMerger(
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, $appId, $pem), new MockClock('@'.self::NOW)),
            $installations,
        );
    }

    private function failure(ForgePullRequest $pullRequest, ?string $appId = '123456'): PullRequestWriteFailed
    {
        try {
            $this->merger($appId)->merge($pullRequest, 'squash', 'head1');
        } catch (PullRequestWriteFailed $e) {
            return $e;
        }

        self::fail('Expected PullRequestWriteFailed.');
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
