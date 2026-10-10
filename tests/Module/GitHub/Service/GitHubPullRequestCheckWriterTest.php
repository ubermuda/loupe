<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\PullRequestCheckAnnotation;
use App\Module\Forge\Service\PullRequestCheckAnnotationLevel;
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

        $runId = $this->writer()->publish($pullRequest, 'Loupe site review', 'abc123', $conclusion, '2 open notes', 'Fix the footer.', null, []);

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

        $runId = $this->writer()->publish($pullRequest, 'Loupe site review', 'abc123', PullRequestCheckConclusion::Success, 'No open note', 'Done.', 4242, []);

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

        $this->writer()->publish($pullRequest, 'Loupe site review', 'abc123', PullRequestCheckConclusion::Failure, 'Notes', str_repeat('é', 70_000), null, []);

        $output = $this->sentBody()['output'] ?? null;
        self::assertIsArray($output);
        self::assertSame(60_000, mb_strlen((string) $output['summary']));
    }

    /** @return iterable<string, array{int, ?int, list<int>}> */
    public static function annotationPages(): iterable
    {
        yield 'no annotation on a new run' => [0, null, [0]];
        yield '50 annotations on a new run' => [50, null, [50]];
        yield '120 annotations on a new run' => [120, null, [50, 50, 20]];
        yield '120 annotations on an updated run' => [120, 4242, [50, 50, 20]];
    }

    /** @param list<int> $perRequest */
    #[DataProvider('annotationPages')]
    public function test_annotations_go_50_to_a_request_and_the_rest_patch_the_run(int $count, ?int $runId, array $perRequest): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_040 + $count + ($runId ?? 0));
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201)];
        foreach ($perRequest as $_) {
            $this->responses[] = $this->answer(['id' => 4242], 201);
        }
        $annotations = [];
        for ($i = 1; $i <= $count; ++$i) {
            $annotations[] = new PullRequestCheckAnnotation('src/page.html', $i, $i + 1, PullRequestCheckAnnotationLevel::Warning, 'Note '.$i, 'Fix line '.$i.'.');
        }

        $result = $this->writer()->publish($pullRequest, 'Loupe site review', 'abc123', PullRequestCheckConclusion::Failure, 'Notes', 'Summary', $runId, $annotations);

        self::assertSame(4242, $result);
        self::assertCount(1 + \count($perRequest), $this->requests);
        self::assertSame(null === $runId ? 'POST' : 'PATCH', $this->requests[1]['method']);
        $sent = [];
        foreach ($perRequest as $page => $expected) {
            $body = $this->sentBody($page + 1);
            $output = $body['output'] ?? null;
            self::assertIsArray($output);
            self::assertSame('Notes', $output['title']);
            self::assertSame('Summary', $output['summary']);
            $pageAnnotations = $output['annotations'] ?? [];
            self::assertIsArray($pageAnnotations);
            self::assertCount($expected, $pageAnnotations);
            if ($page > 0) {
                self::assertSame('PATCH', $this->requests[$page + 1]['method']);
                self::assertSame('https://api.github.com/repos/ubermuda/loupe/check-runs/4242', $this->requests[$page + 1]['url']);
                self::assertSame(['output'], array_keys($body));
            }
            $sent = [...$sent, ...$pageAnnotations];
        }
        if ($count > 0) {
            self::assertSame([
                'path' => 'src/page.html',
                'start_line' => $count,
                'end_line' => $count + 1,
                'annotation_level' => 'warning',
                'title' => 'Note '.$count,
                'message' => 'Fix line '.$count.'.',
            ], $sent[$count - 1]);
        }
    }

    public function test_a_check_with_no_annotation_sends_no_annotations_key(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_200);
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer(['id' => 1], 201)];

        $this->writer()->publish($pullRequest, 'Loupe site review', 'abc123', PullRequestCheckConclusion::Success, 'Clean', 'Done.', null, []);

        $output = $this->sentBody()['output'] ?? null;
        self::assertIsArray($output);
        self::assertArrayNotHasKey('annotations', $output);
    }

    public function test_a_long_annotation_title_and_message_are_cut(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_201);
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer(['id' => 1], 201)];
        $annotation = new PullRequestCheckAnnotation('a.html', 1, 1, PullRequestCheckAnnotationLevel::Failure, str_repeat('t', 300), str_repeat('é', 70_000));

        $this->writer()->publish($pullRequest, 'Loupe site review', 'abc123', PullRequestCheckConclusion::Failure, 'Notes', 'Summary', null, [$annotation]);

        $output = $this->sentBody()['output'] ?? null;
        self::assertIsArray($output);
        $sent = $output['annotations'][0] ?? null;
        self::assertIsArray($sent);
        self::assertSame(255, mb_strlen((string) $sent['title']));
        self::assertSame(str_repeat('é', 30_000), $sent['message']);
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

    public function test_a_failure_on_a_later_page_names_the_run_and_the_annotations_it_holds(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_033);
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer(['id' => 4242], 201), new MockResponse('{}', ['http_code' => 502])];
        $annotations = [];
        for ($i = 1; $i <= 120; ++$i) {
            $annotations[] = new PullRequestCheckAnnotation('src/page.html', $i, $i, PullRequestCheckAnnotationLevel::Warning, 'Note '.$i, 'Fix line '.$i.'.');
        }

        try {
            $this->writer()->publish($pullRequest, 'Loupe agent review', 'abc123', PullRequestCheckConclusion::Failure, 'Notes', 'Summary', null, $annotations);
            self::fail('Expected PullRequestCheckFailed.');
        } catch (PullRequestCheckFailed $e) {
            self::assertSame('api_failed_http_status_502', $e->cause);
            self::assertSame(4242, $e->runId);
            self::assertSame(50, $e->annotationsSent);
        }
        self::assertCount(3, $this->requests);
    }

    public function test_a_later_page_that_github_accepts_with_a_body_that_does_not_decode_counts_as_sent(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_033);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['id' => 4242], 201),
            new MockResponse('not json', ['http_code' => 200]),
            new MockResponse('{}', ['http_code' => 502]),
        ];
        $annotations = [];
        for ($i = 1; $i <= 120; ++$i) {
            $annotations[] = new PullRequestCheckAnnotation('src/page.html', $i, $i, PullRequestCheckAnnotationLevel::Warning, 'Note '.$i, 'Fix line '.$i.'.');
        }

        try {
            $this->writer()->publish($pullRequest, 'Loupe agent review', 'abc123', PullRequestCheckConclusion::Failure, 'Notes', 'Summary', null, $annotations);
            self::fail('Expected PullRequestCheckFailed.');
        } catch (PullRequestCheckFailed $e) {
            self::assertSame(4242, $e->runId);
            self::assertSame(100, $e->annotationsSent);
        }
    }

    public function test_a_failure_on_the_first_request_of_a_new_run_names_no_run(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_034);
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), new MockResponse('{}', ['http_code' => 502])];

        $failure = $this->failure($pullRequest);

        self::assertNull($failure->runId);
        self::assertSame(0, $failure->annotationsSent);
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
    private function sentBody(int $request = 1): array
    {
        $body = $this->requests[$request]['options']['body'] ?? null;
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
            $this->writer()->publish($pullRequest, 'Loupe site review', 'abc123', PullRequestCheckConclusion::Failure, 'Notes', 'Summary', null, []);
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
