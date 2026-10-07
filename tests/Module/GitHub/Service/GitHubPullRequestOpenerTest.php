<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\PullRequestOpeners;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubPullRequestInstallations;
use App\Module\GitHub\Service\GitHubPullRequestOpener;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubPullRequestOpenerTest extends KernelTestCase
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

    public function test_the_registry_hands_github_pull_requests_to_this_opener(): void
    {
        $opener = $this->opener();
        $openers = new PullRequestOpeners([$opener]);

        self::assertSame($opener, $openers->for('github'));
        self::assertNull($openers->for('gitlab'));
    }

    public function test_it_opens_a_draft_pull_request_when_none_is_open(): void
    {
        $from = $this->tracked('Ubermuda/Loupe.site', 73_001);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer([]),
            $this->answer(['number' => 812], 201),
        ];

        self::assertSame(812, $this->opener()->open($from, 'epic/42', 'main', 'The epic', 'The body'));

        self::assertCount(3, $this->requests);
        self::assertSame('GET', $this->requests[1]['method']);
        self::assertSame('https://api.github.com/repos/Ubermuda/Loupe.site/pulls?head=Ubermuda:epic/42&base=main&state=open', $this->requests[1]['url']);
        self::assertSame('POST', $this->requests[2]['method']);
        self::assertSame('https://api.github.com/repos/Ubermuda/Loupe.site/pulls', $this->requests[2]['url']);
        self::assertSame(
            ['title' => 'The epic', 'head' => 'epic/42', 'base' => 'main', 'body' => 'The body', 'draft' => true],
            json_decode($this->body(2), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function test_it_answers_the_open_pull_request_that_exists(): void
    {
        $from = $this->tracked('ubermuda/loupe', 73_002);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer([['number' => 640]]),
        ];

        self::assertSame(640, $this->opener()->open($from, 'epic/42', 'main', 'The epic', 'The body'));
        self::assertCount(2, $this->requests);
    }

    public function test_an_answer_with_no_number_is_a_permanent_failure(): void
    {
        $from = $this->tracked('ubermuda/loupe', 73_003);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer([]),
            $this->answer(['id' => 1], 201),
        ];

        $failure = $this->failure(fn () => $this->opener()->open($from, 'epic/42', 'main', 'The epic', 'The body'));

        self::assertSame('malformed_body', $failure->cause);
        self::assertTrue($failure->permanent);
    }

    public function test_a_refused_request_is_a_permanent_permission_failure(): void
    {
        $from = $this->tracked('ubermuda/loupe', 73_004);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{"message":"No"}', ['http_code' => 403]),
        ];

        $failure = $this->failure(fn () => $this->opener()->open($from, 'epic/42', 'main', 'The epic', 'The body'));

        self::assertSame('permission', $failure->cause);
        self::assertTrue($failure->permanent);
    }

    public function test_a_validation_error_is_a_failure_that_names_the_status(): void
    {
        $from = $this->tracked('ubermuda/loupe', 73_005);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer([]),
            new MockResponse('{"message":"Validation Failed"}', ['http_code' => 422]),
        ];

        $failure = $this->failure(fn () => $this->opener()->open($from, 'epic/42', 'main', 'The epic', 'The body'));

        self::assertSame('api_failed_http_status_422', $failure->cause);
        self::assertFalse($failure->permanent);
    }

    public function test_a_rate_limit_carries_the_delay_github_asks_for(): void
    {
        $from = $this->tracked('ubermuda/loupe', 73_006);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{"message":"API rate limit exceeded"}', ['http_code' => 403, 'response_headers' => ['retry-after' => '60']]),
        ];

        $failure = $this->failure(fn () => $this->opener()->open($from, 'epic/42', 'main', 'The epic', 'The body'));

        self::assertSame('api_failed_rate_limited', $failure->cause);
        self::assertSame(60, $failure->retryAfterSeconds);
    }

    public function test_a_repository_without_an_installation_is_a_permanent_failure(): void
    {
        $project = $this->project();
        $this->em->persist(new ForgeRepository($project, 'github', '604', 'ubermuda/loupe', ForgeRepositorySource::Hook));
        $from = new ForgePullRequest($project, 'github', 'ubermuda/loupe', 604);
        $this->em->persist($from);
        $this->em->flush();

        $failure = $this->failure(fn () => $this->opener()->open($from, 'epic/42', 'main', 'The epic', 'The body'));

        self::assertSame('no_installation', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->requests);
    }

    public function test_the_url_names_the_pull_request_on_github(): void
    {
        $from = new ForgePullRequest($this->project(), 'github', 'Ubermuda/Loupe', 604);

        self::assertSame('https://github.com/ubermuda/loupe/pull/812', $this->opener()->url($from, 812));
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
        $user = new User(fullName: 'Riley', email: 'opener-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'opener-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);

        return $project;
    }

    private function opener(): GitHubPullRequestOpener
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

        return new GitHubPullRequestOpener(
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, '123456', $pem), new MockClock('@'.self::NOW)),
            $installations,
        );
    }

    private function failure(\Closure $call): PullRequestWriteFailed
    {
        try {
            $call();
        } catch (PullRequestWriteFailed $e) {
            return $e;
        }

        self::fail('Expected PullRequestWriteFailed.');
    }

    private function body(int $request): string
    {
        $body = $this->requests[$request]['options']['body'] ?? null;
        self::assertIsString($body);

        return $body;
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
