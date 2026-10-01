<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\PullRequestStateWriters;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubPullRequestInstallations;
use App\Module\GitHub\Service\GitHubPullRequestStateWriter;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubPullRequestStateWriterTest extends KernelTestCase
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

    public function test_the_registry_hands_github_pull_requests_to_this_writer(): void
    {
        $writer = $this->writer();
        $writers = new PullRequestStateWriters([$writer]);

        self::assertSame($writer, $writers->for('github'));
        self::assertNull($writers->for('gitlab'));
    }

    public function test_it_reads_the_pull_request_then_marks_a_draft_ready_for_review(): void
    {
        $pullRequest = $this->tracked('Ubermuda/Loupe.site', 72_001);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->node(isDraft: true),
            $this->answer(['data' => ['markPullRequestReadyForReview' => ['pullRequest' => ['isDraft' => false]]]]),
        ];

        $this->writer()->setDraft($pullRequest, false);

        self::assertCount(3, $this->requests);
        self::assertSame(['owner' => 'Ubermuda', 'name' => 'Loupe.site', 'number' => 604], $this->graphql(1)['variables']);
        self::assertStringContainsString('pullRequest(number:$number){id isDraft state}', $this->graphql(1)['query']);
        self::assertStringContainsString('markPullRequestReadyForReview(input:{pullRequestId:$id})', $this->graphql(2)['query']);
        self::assertSame(['id' => 'PR_node'], $this->graphql(2)['variables']);
    }

    public function test_it_converts_an_open_pull_request_to_a_draft(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_002);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->node(isDraft: false),
            $this->answer(['data' => ['convertPullRequestToDraft' => ['pullRequest' => ['isDraft' => true]]]]),
        ];

        $this->writer()->setDraft($pullRequest, true);

        self::assertCount(3, $this->requests);
        self::assertStringContainsString('convertPullRequestToDraft(input:{pullRequestId:$id})', $this->graphql(2)['query']);
    }

    /** @return iterable<string, array{bool}> */
    public static function targets(): iterable
    {
        yield 'already a draft' => [true];
        yield 'already ready for review' => [false];
    }

    #[DataProvider('targets')]
    public function test_a_pull_request_already_in_the_target_state_takes_no_mutation(bool $draft): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_003 + (int) $draft);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->node(isDraft: $draft),
        ];

        $this->writer()->setDraft($pullRequest, $draft);

        self::assertCount(2, $this->requests);
    }

    /** @return iterable<string, array{string, int}> */
    public static function endedStates(): iterable
    {
        yield 'closed' => ['CLOSED', 1];
        yield 'merged' => ['MERGED', 2];
    }

    #[DataProvider('endedStates')]
    public function test_a_pull_request_that_is_not_open_takes_no_draft_mutation(string $state, int $offset): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_010 + $offset);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->node(isDraft: true, state: $state),
        ];

        $this->writer()->setDraft($pullRequest, false);

        self::assertCount(2, $this->requests);
    }

    #[DataProvider('endedStates')]
    public function test_a_pull_request_that_is_not_open_is_not_closed_again(string $state, int $offset): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_020 + $offset);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->node(isDraft: false, state: $state),
        ];

        $this->writer()->close($pullRequest);

        self::assertCount(2, $this->requests);
    }

    public function test_it_closes_an_open_pull_request(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_030);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->node(isDraft: true),
            $this->answer(['data' => ['closePullRequest' => ['pullRequest' => ['state' => 'CLOSED']]]]),
        ];

        $this->writer()->close($pullRequest);

        self::assertCount(3, $this->requests);
        self::assertStringContainsString('closePullRequest(input:{pullRequestId:$id})', $this->graphql(2)['query']);
        self::assertSame(['id' => 'PR_node'], $this->graphql(2)['variables']);
    }

    public function test_a_forbidden_mutation_is_a_permanent_permission_failure(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_040);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->node(isDraft: false),
            $this->answer(['data' => ['closePullRequest' => null], 'errors' => [['type' => 'FORBIDDEN', 'message' => 'Resource not accessible by integration']]]),
        ];

        $failure = $this->failure(fn () => $this->writer()->close($pullRequest));

        self::assertSame('permission', $failure->cause);
        self::assertTrue($failure->permanent);
    }

    public function test_another_graphql_error_is_a_transient_failure(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_041);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['data' => null, 'errors' => [['type' => 'SERVICE_UNAVAILABLE']]]),
        ];

        $failure = $this->failure(fn () => $this->writer()->close($pullRequest));

        self::assertSame('api_failed_graphql_error', $failure->cause);
        self::assertFalse($failure->permanent);
    }

    public function test_a_graphql_rate_limit_is_a_transient_failure_with_a_fallback_delay(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_046);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['data' => null, 'errors' => [['type' => 'RATE_LIMITED', 'message' => 'API rate limit exceeded']]]),
        ];

        $failure = $this->failure(fn () => $this->writer()->close($pullRequest));

        self::assertSame('api_failed_rate_limited', $failure->cause);
        self::assertFalse($failure->permanent);
        self::assertSame(60, $failure->retryAfterSeconds);
    }

    public function test_a_missing_pull_request_is_a_permanent_not_found_failure(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_042);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['data' => ['repository' => ['pullRequest' => null]], 'errors' => [['type' => 'NOT_FOUND']]]),
        ];

        $failure = $this->failure(fn () => $this->writer()->setDraft($pullRequest, false));

        self::assertSame('not_found', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertCount(2, $this->requests);
    }

    public function test_a_rate_limit_is_a_transient_failure_that_carries_the_delay_github_asks_for(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_043);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{"message":"API rate limit exceeded"}', ['http_code' => 403, 'response_headers' => ['retry-after' => '60']]),
        ];

        $failure = $this->failure(fn () => $this->writer()->setDraft($pullRequest, false));

        self::assertSame('api_failed_rate_limited', $failure->cause);
        self::assertFalse($failure->permanent);
        self::assertSame(60, $failure->retryAfterSeconds);
    }

    /** @return iterable<string, array{int}> */
    public static function refusals(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
        yield '404' => [404];
    }

    #[DataProvider('refusals')]
    public function test_a_refused_request_is_a_permanent_permission_failure(int $status): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_100 + $status);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{"message":"No"}', ['http_code' => $status]),
        ];

        $failure = $this->failure(fn () => $this->writer()->close($pullRequest));

        self::assertSame('permission', $failure->cause);
        self::assertTrue($failure->permanent);
    }

    public function test_a_server_error_is_a_transient_failure_that_names_the_status(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_044);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{"message":"Server Error"}', ['http_code' => 502]),
        ];

        $failure = $this->failure(fn () => $this->writer()->close($pullRequest));

        self::assertSame('api_failed_http_status_502', $failure->cause);
        self::assertFalse($failure->permanent);
    }

    public function test_a_missing_app_configuration_is_a_permanent_failure(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_045);

        $failure = $this->failure(fn () => $this->writer(appId: null)->close($pullRequest));

        self::assertSame('api_failed_not_configured', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->requests);
    }

    public function test_a_repository_without_an_installation_is_a_permanent_failure(): void
    {
        $project = $this->project();
        $this->em->persist(new ForgeRepository($project, 'github', '604', 'ubermuda/loupe', ForgeRepositorySource::Hook));
        $pullRequest = new ForgePullRequest($project, 'github', 'ubermuda/loupe', 604);
        $this->em->persist($pullRequest);
        $this->em->flush();

        $failure = $this->failure(fn () => $this->writer()->setDraft($pullRequest, true));

        self::assertSame('no_installation', $failure->cause);
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
        $user = new User(fullName: 'Riley', email: 'state-writer-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'state-writer-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);

        return $project;
    }

    private function writer(?string $appId = '123456'): GitHubPullRequestStateWriter
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

        return new GitHubPullRequestStateWriter(
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, $appId, $pem), new MockClock('@'.self::NOW)),
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

    /** @return array{query: string, variables: array<string, mixed>} */
    private function graphql(int $request): array
    {
        self::assertSame('https://api.github.com/graphql', $this->requests[$request]['url']);
        $body = $this->requests[$request]['options']['body'] ?? null;
        self::assertIsString($body);
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['query'] ?? null);
        self::assertIsArray($decoded['variables'] ?? null);

        /** @var array<string, mixed> $variables */
        $variables = $decoded['variables'];

        return ['query' => $decoded['query'], 'variables' => $variables];
    }

    private function node(bool $isDraft, string $state = 'OPEN'): MockResponse
    {
        return $this->answer(['data' => ['repository' => ['pullRequest' => ['id' => 'PR_node', 'isDraft' => $isDraft, 'state' => $state]]]]);
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
