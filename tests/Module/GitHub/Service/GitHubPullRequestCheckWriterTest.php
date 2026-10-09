<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\PullRequestCheckConclusion;
use App\Module\Forge\Service\PullRequestCheckFailed;
use App\Module\Forge\Service\PullRequestCheckWriters;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubPullRequestCheckWriter;
use App\Module\GitHub\Service\GitHubPullRequestInstallations;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubPullRequestCheckWriterTest extends KernelTestCase
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
        $writers = new PullRequestCheckWriters([$writer]);

        self::assertSame($writer, $writers->for('github'));
        self::assertNull($writers->for('gitlab'));
    }

    /** @return iterable<string, array{PullRequestCheckConclusion}> */
    public static function conclusions(): iterable
    {
        yield 'success' => [PullRequestCheckConclusion::Success];
        yield 'failure' => [PullRequestCheckConclusion::Failure];
        yield 'neutral' => [PullRequestCheckConclusion::Neutral];
    }

    #[DataProvider('conclusions')]
    public function test_it_creates_a_completed_check_run_as_the_app(PullRequestCheckConclusion $conclusion): void
    {
        $pullRequest = $this->tracked('Ubermuda/Loupe.site', 72_001 + \count($this->requests));
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer(['id' => 4242], 201)];

        $runId = $this->writer()->publish($pullRequest, 'Loupe site review', 'abc123', $conclusion, '2 open notes', 'Fix the footer.', null);

        self::assertSame(4242, $runId);
        self::assertCount(2, $this->requests);
        self::assertSame('POST', $this->requests[1]['method']);
        self::assertSame('https://api.github.com/repos/Ubermuda/Loupe.site/check-runs', $this->requests[1]['url']);
        self::assertSame([
            'name' => 'Loupe site review',
            'head_sha' => 'abc123',
            'status' => 'completed',
            'conclusion' => $conclusion->value,
            'output' => ['title' => '2 open notes', 'summary' => 'Fix the footer.'],
        ], $this->sentBody());
    }

    public function test_a_run_id_updates_the_run_and_sends_no_name_or_sha(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_010);
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer(['id' => 4242])];

        $runId = $this->writer()->publish($pullRequest, 'Loupe site review', 'abc123', PullRequestCheckConclusion::Success, 'No open note', 'Done.', 4242);

        self::assertSame(4242, $runId);
        self::assertSame('PATCH', $this->requests[1]['method']);
        self::assertSame('https://api.github.com/repos/ubermuda/loupe/check-runs/4242', $this->requests[1]['url']);
        self::assertSame([
            'status' => 'completed',
            'conclusion' => 'success',
            'output' => ['title' => 'No open note', 'summary' => 'Done.'],
        ], $this->sentBody());
    }

    public function test_a_long_summary_is_cut_to_stay_under_the_github_limit(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_011);
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer(['id' => 1], 201)];

        $this->writer()->publish($pullRequest, 'Loupe site review', 'abc123', PullRequestCheckConclusion::Failure, 'Notes', str_repeat('é', 70_000), null);

        $output = $this->sentBody()['output'] ?? null;
        self::assertIsArray($output);
        self::assertSame(60_000, mb_strlen((string) $output['summary']));
    }

    /** @return iterable<string, array{int}> */
    public static function refusals(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
        yield '404' => [404];
    }

    #[DataProvider('refusals')]
    public function test_a_refused_check_is_a_permanent_permission_failure(int $status): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_020 + $status);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{"message":"Resource not accessible by integration"}', ['http_code' => $status]),
        ];

        $failure = $this->failure($pullRequest);

        self::assertSame('permission', $failure->cause);
        self::assertTrue($failure->permanent);
    }

    public function test_a_rate_limit_is_a_transient_failure_that_carries_the_delay(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_030);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            new MockResponse('{}', ['http_code' => 403, 'response_headers' => ['retry-after' => '60']]),
        ];

        $failure = $this->failure($pullRequest);

        self::assertSame('api_failed_rate_limited', $failure->cause);
        self::assertFalse($failure->permanent);
        self::assertSame(60, $failure->retryAfterSeconds);
    }

    public function test_a_server_error_is_a_transient_failure_that_names_the_status(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_031);
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), new MockResponse('{}', ['http_code' => 502])];

        $failure = $this->failure($pullRequest);

        self::assertSame('api_failed_http_status_502', $failure->cause);
        self::assertFalse($failure->permanent);
    }

    public function test_an_answer_with_no_run_id_is_a_transient_failure(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_032);
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer(['name' => 'x'], 201)];

        $failure = $this->failure($pullRequest);

        self::assertSame('api_failed_malformed_body', $failure->cause);
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

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        $body = $this->requests[1]['options']['body'] ?? null;
        self::assertIsString($body);
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
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
        $user = new User(fullName: 'Riley', email: 'check-writer-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'check-writer-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);

        return $project;
    }

    private function writer(): GitHubPullRequestCheckWriter
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

        return new GitHubPullRequestCheckWriter(
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, '123456', $pem), new MockClock('@'.self::NOW)),
            $installations,
        );
    }

    private function failure(ForgePullRequest $pullRequest): PullRequestCheckFailed
    {
        try {
            $this->writer()->publish($pullRequest, 'Loupe site review', 'abc123', PullRequestCheckConclusion::Failure, 'Notes', 'Summary', null);
        } catch (PullRequestCheckFailed $e) {
            return $e;
        }

        self::fail('Expected PullRequestCheckFailed.');
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
