<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Service\PullRequestStateReaders;
use App\Module\Forge\Service\PullRequestUnreadable;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubPullRequestInstallations;
use App\Module\GitHub\Service\GitHubPullRequestStateMapper;
use App\Module\GitHub\Service\GitHubPullRequestStateReader;
use App\Module\Project\Entity\Project;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubPullRequestStateReaderTest extends KernelTestCase
{
    private const string NOW = '2026-09-27 12:00:00';
    private const string HEAD = '0a80537839ea354257ded862e7e407fc85bf1487';

    private EntityManagerInterface $em;
    private MockClock $clock;
    private RecordingLogger $logger;

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
        $this->clock = new MockClock(self::NOW);
        $this->logger = new RecordingLogger();
    }

    public function test_the_registry_hands_github_pull_requests_to_this_reader(): void
    {
        $readers = self::getContainer()->get(PullRequestStateReaders::class);
        self::assertInstanceOf(PullRequestStateReaders::class, $readers);

        self::assertInstanceOf(GitHubPullRequestStateReader::class, $readers->for('github'));
    }

    public function test_it_reads_pull_request_604_with_the_branch_rules_and_the_compare(): void
    {
        $pullRequest = $this->tracked('Ubermuda/Loupe', 70_001);
        $graphql = $this->fixture('graphql');
        $graphql['data']['repository']['pullRequest']['headRefName'] = 'card-604';
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($graphql),
            $this->answer($this->fixture('rules')),
            $this->answer($this->fixture('compare')),
        ];

        $snapshot = $this->reader()->read($pullRequest);

        self::assertSame(PullRequestChecks::Pending, $snapshot->checks);
        self::assertSame(PullRequestMergeability::Blocked, $snapshot->mergeability);
        self::assertSame(self::HEAD, $snapshot->headSha);
        self::assertSame('card-604', $snapshot->headBranch);
        self::assertCount(4, $this->requests);
        self::assertSame('https://api.github.com/app/installations/70001/access_tokens', $this->requests[0]['url']);
        self::assertSame('https://api.github.com/graphql', $this->requests[1]['url']);
        $body = json_decode($this->requests[1]['options']['body'] ?? '', true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['owner' => 'Ubermuda', 'name' => 'Loupe', 'n' => 604, 'after' => null], $body['variables']);
        self::assertIsString($body['query'] ?? null);
        self::assertStringContainsString('latestOpinionatedReviews(first:100,writersOnly:true){nodes{id state submittedAt commit{oid}}}', $body['query']);
        self::assertStringContainsString(' baseRepository{defaultBranchRef{name}} ', $body['query']);
        self::assertStringContainsString('commit{oid parents(first:3){nodes{oid}} statusCheckRollup', $body['query']);
        self::assertStringNotContainsString('reviews(last:1', $body['query']);
        self::assertStringContainsString(' createdAt mergedAt ', $body['query']);
        self::assertStringContainsString(' headRefOid headRefName baseRefName ', $body['query']);
        self::assertSame('https://api.github.com/repos/Ubermuda/Loupe/rules/branches/main?per_page=100&page=1', $this->requests[2]['url']);
        self::assertSame('https://api.github.com/repos/Ubermuda/Loupe/compare/main...'.self::HEAD, $this->requests[3]['url']);
    }

    public function test_every_page_of_the_check_contexts_is_read(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_010);
        $first = $this->fixture('graphql');
        $first['data']['repository']['pullRequest']['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts'] = [
            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
            'nodes' => [['__typename' => 'CheckRun', 'name' => 'lint', 'status' => 'COMPLETED', 'conclusion' => 'SUCCESS', 'isRequired' => true]],
        ];
        $first['data']['repository']['pullRequest']['latestOpinionatedReviews'] = ['nodes' => [['state' => 'CHANGES_REQUESTED', 'commit' => ['oid' => 'abc1234']]]];
        $second = $first;
        $second['data']['repository']['pullRequest']['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts'] = [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'cursor-2'],
            'nodes' => [['__typename' => 'CheckRun', 'name' => 'e2e', 'status' => 'COMPLETED', 'conclusion' => 'FAILURE', 'isRequired' => true]],
        ];
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($first),
            $this->answer($second),
            $this->answer([['type' => 'required_status_checks', 'parameters' => ['strict_required_status_checks_policy' => false, 'required_status_checks' => [['context' => 'lint'], ['context' => 'e2e']]]]]),
        ];

        $snapshot = $this->reader()->read($pullRequest);

        self::assertSame(PullRequestChecks::Failed, $snapshot->checks);
        self::assertSame(['e2e'], $snapshot->failedChecks);
        self::assertSame('abc1234', $snapshot->changesRequestedSha);
        $body = json_decode($this->requests[2]['options']['body'] ?? '', true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('cursor-1', $body['variables']['after'] ?? null);
    }

    public function test_a_head_that_moves_between_pages_is_a_transient_failure(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_011);
        $first = $this->fixture('graphql');
        $first['data']['repository']['pullRequest']['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => 'cursor-1'];
        $second = $first;
        $second['data']['repository']['pullRequest']['commits']['nodes'][0]['commit']['oid'] = 'another-head';
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($first),
            $this->answer($second),
        ];

        try {
            $this->reader()->read($pullRequest);
            self::fail('A moved head must not mix the checks of two commits.');
        } catch (PullRequestUnreadable $e) {
            self::assertSame('head_moved', $e->reason);
            self::assertTrue($e->transient);
        }
    }

    public function test_every_page_of_the_branch_rules_is_read(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_013);
        $graphql = $this->fixture('graphql');
        $graphql['data']['repository']['pullRequest']['mergeable'] = 'CONFLICTING';
        $filler = array_fill(0, 100, ['type' => 'deletion']);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($graphql),
            $this->answer($filler),
            $this->answer([['type' => 'required_status_checks', 'parameters' => ['strict_required_status_checks_policy' => true, 'required_status_checks' => [['context' => 'late-check']]]]]),
        ];

        $snapshot = $this->reader()->read($pullRequest);

        self::assertSame(PullRequestChecks::Pending, $snapshot->checks);
        self::assertCount(4, $this->requests);
        self::assertStringEndsWith('/rules/branches/main?per_page=100&page=2', $this->requests[3]['url']);
    }

    public function test_behind_the_base_under_strict_rules_reads_as_behind(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_002);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($this->fixture('graphql')),
            $this->answer($this->fixture('rules')),
            $this->answer(['ahead_by' => 8, 'behind_by' => 2, 'status' => 'diverged']),
        ];

        self::assertSame(PullRequestMergeability::Behind, $this->reader()->read($pullRequest)->mergeability);
    }

    public function test_the_branch_rules_are_cached_for_five_minutes(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_003);
        $graphql = $this->fixture('graphql');
        $graphql['data']['repository']['pullRequest']['mergeable'] = 'CONFLICTING';
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($graphql),
            $this->answer($this->fixture('rules')),
            $this->answer($graphql),
            $this->answer($graphql),
            $this->answer($this->fixture('rules')),
        ];
        $reader = $this->reader();

        $reader->read($pullRequest);
        $this->clock->modify('+4 minutes');
        $reader->read($pullRequest);
        self::assertCount(4, $this->requests);

        $this->clock->modify('+1 minute');
        $reader->read($pullRequest);
        self::assertCount(6, $this->requests);
        self::assertStringEndsWith('/rules/branches/main?per_page=100&page=1', $this->requests[5]['url']);
    }

    public function test_unreadable_branch_rules_fall_back_to_the_required_flags_and_skip_the_compare(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_004);
        $graphql = $this->fixture('graphql');
        $graphql['data']['repository']['pullRequest']['baseRefName'] = 'release/1.x';
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($graphql),
            new MockResponse('{"message":"Server Error"}', ['http_code' => 502]),
        ];

        $snapshot = $this->reader()->read($pullRequest);

        self::assertSame(PullRequestChecks::Pending, $snapshot->checks);
        self::assertCount(3, $this->requests);
        self::assertSame('https://api.github.com/repos/ubermuda/loupe/rules/branches/release%2F1.x?per_page=100&page=1', $this->requests[2]['url']);
        self::assertSame('forge.ruleset_unreadable', $this->logger->records[0]['message']);
        self::assertSame('http_status', $this->logger->records[0]['context']['reason']);
    }

    public function test_a_base_without_rules_inherits_the_required_checks_of_the_default_branch(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_014);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($this->ruleLessGraphql('epic/436')),
            $this->answer([['type' => 'deletion']]),
            $this->answer($this->fixture('rules')),
        ];

        $snapshot = $this->reader()->read($pullRequest);

        self::assertSame(PullRequestChecks::Pending, $snapshot->checks);
        self::assertSame(PullRequestMergeability::Mergeable, $snapshot->mergeability);
        self::assertCount(4, $this->requests);
        self::assertStringEndsWith('/rules/branches/epic%2F436?per_page=100&page=1', $this->requests[2]['url']);
        self::assertStringEndsWith('/rules/branches/main?per_page=100&page=1', $this->requests[3]['url']);
    }

    public function test_the_default_branch_without_rules_requires_no_check(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_015);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($this->ruleLessGraphql('main')),
            $this->answer([]),
        ];

        $snapshot = $this->reader()->read($pullRequest);

        self::assertSame(PullRequestChecks::Passed, $snapshot->checks);
        self::assertCount(3, $this->requests);
    }

    public function test_a_base_without_rules_and_unreadable_default_branch_rules_is_a_transient_failure(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_016);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($this->ruleLessGraphql('epic/436')),
            $this->answer([]),
            new MockResponse('{"message":"Server Error"}', ['http_code' => 502]),
        ];

        try {
            $this->reader()->read($pullRequest);
            self::fail('A base without rules must not read as passed while the default branch rules are unknown.');
        } catch (PullRequestUnreadable $e) {
            self::assertSame('default_branch_rules_unreadable', $e->reason);
            self::assertTrue($e->transient);
        }

        self::assertCount(4, $this->requests);
        self::assertStringEndsWith('/rules/branches/main?per_page=100&page=1', $this->requests[3]['url']);
        self::assertSame('forge.ruleset_unreadable', $this->logger->records[0]['message']);
        self::assertSame('main', $this->logger->records[0]['context']['base']);
    }

    public function test_unreadable_rules_of_a_base_that_is_not_the_default_branch_are_a_transient_failure(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_017);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($this->ruleLessGraphql('epic/436')),
            new MockResponse('{"message":"Server Error"}', ['http_code' => 502]),
        ];

        try {
            $this->reader()->read($pullRequest);
            self::fail('A base that is not the default branch must not read as passed while its rules are unknown.');
        } catch (PullRequestUnreadable $e) {
            self::assertSame('base_rules_unreadable', $e->reason);
            self::assertTrue($e->transient);
        }

        self::assertCount(3, $this->requests);
        self::assertStringEndsWith('/rules/branches/epic%2F436?per_page=100&page=1', $this->requests[2]['url']);
    }

    public function test_a_failed_compare_is_logged_and_ignored(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_005);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer($this->fixture('graphql')),
            $this->answer($this->fixture('rules')),
            new MockResponse('{"message":"Not Found"}', ['http_code' => 404]),
        ];

        self::assertSame(PullRequestMergeability::Blocked, $this->reader()->read($pullRequest)->mergeability);
        self::assertSame('forge.compare_unreadable', $this->logger->records[0]['message']);
    }

    public function test_a_missing_pull_request_is_not_found(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_006);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['data' => ['repository' => ['pullRequest' => null]], 'errors' => [['type' => 'NOT_FOUND']]]),
        ];

        self::assertSame('not_found', $this->unreadable($pullRequest));
    }

    public function test_a_graphql_answer_without_data_is_transient(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_012);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['errors' => [['type' => 'RATE_LIMITED']]]),
        ];

        try {
            $this->reader()->read($pullRequest);
            self::fail('A GraphQL answer without data must be unreadable.');
        } catch (PullRequestUnreadable $e) {
            self::assertSame('api_failed_graphql_error', $e->reason);
            self::assertTrue($e->transient);
        }
    }

    public function test_a_failed_api_call_names_its_reason(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_007);
        $this->responses = [new MockResponse('', ['http_code' => 401])];

        self::assertSame('api_failed_http_status', $this->unreadable($pullRequest));
    }

    public function test_a_repository_without_an_installation_row_is_unreadable(): void
    {
        $project = $this->project();
        $this->em->persist(new ForgeRepository($project, 'github', '604', 'ubermuda/loupe', ForgeRepositorySource::Hook));
        $pullRequest = new ForgePullRequest($project, 'github', 'ubermuda/loupe', 604);
        $this->em->persist($pullRequest);
        $this->em->flush();

        self::assertSame('no_installation', $this->unreadable($pullRequest));
        self::assertSame([], $this->requests);
    }

    public function test_a_removed_installation_is_unreadable(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_008);
        $this->installationOf(70_008)->removedAt = new \DateTimeImmutable();
        $this->em->flush();

        self::assertSame('no_installation', $this->unreadable($pullRequest));
    }

    public function test_an_installation_of_another_project_is_unreadable(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_009);
        $other = $this->project();
        $this->em->persist(new ForgeRepository($other, 'github', '604', 'ubermuda/loupe', ForgeRepositorySource::Installation, '70009'));
        $pullRequest = new ForgePullRequest($other, 'github', 'ubermuda/loupe', 604);
        $this->em->persist($pullRequest);
        $this->em->flush();

        self::assertSame('no_installation', $this->unreadable($pullRequest));
    }

    public function test_a_suspended_installation_is_unreadable(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 70_010);
        $this->installationOf(70_010)->suspendedAt = new \DateTimeImmutable();
        $this->em->flush();

        self::assertSame('installation_suspended', $this->unreadable($pullRequest));
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

    private function installationOf(int $installationId): GitHubInstallation
    {
        $installations = self::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);

        return $installations->findOneByInstallationId($installationId) ?? throw new \LogicException('No installation.');
    }

    private function project(): Project
    {
        $user = new User(fullName: 'Riley', email: 'reader-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'reader-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);

        return $project;
    }

    private function reader(): GitHubPullRequestStateReader
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

        return new GitHubPullRequestStateReader(
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, '123456', $pem), $this->clock),
            $installations,
            new GitHubPullRequestStateMapper(),
            $this->clock,
            $this->logger,
        );
    }

    private function unreadable(ForgePullRequest $pullRequest): string
    {
        try {
            $this->reader()->read($pullRequest);
        } catch (PullRequestUnreadable $e) {
            return $e->reason;
        }

        self::fail('Expected PullRequestUnreadable.');
    }

    /**
     * A clean pull request on `$base` whose only check passed and is not flagged required.
     *
     * @return array<mixed>
     */
    private function ruleLessGraphql(string $base): array
    {
        $graphql = $this->fixture('graphql');
        $graphql['data']['repository']['pullRequest']['baseRefName'] = $base;
        $graphql['data']['repository']['pullRequest']['baseRepository'] = ['defaultBranchRef' => ['name' => 'main']];
        $graphql['data']['repository']['pullRequest']['mergeStateStatus'] = 'CLEAN';
        $graphql['data']['repository']['pullRequest']['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts']['nodes'] = [
            ['__typename' => 'CheckRun', 'name' => 'lint', 'status' => 'COMPLETED', 'conclusion' => 'SUCCESS', 'isRequired' => false],
        ];

        return $graphql;
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    /** @return array<mixed> */
    private function fixture(string $name): array
    {
        $json = file_get_contents(__DIR__.'/fixtures/pull-request-604-'.$name.'.json');
        self::assertIsString($json);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
