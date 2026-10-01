<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\ApprovalCoverage;
use App\Module\Forge\Service\ApprovalCoverageReaders;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubApprovalCoverageReader;
use App\Module\GitHub\Service\GitHubPullRequestInstallations;
use App\Module\Project\Entity\Project;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubApprovalCoverageReaderTest extends KernelTestCase
{
    private const string COVERED = 'c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0';
    private const string HEAD = 'ffffffffffffffffffffffffffffffffffffffff';

    private EntityManagerInterface $em;
    private RecordingLogger $logger;

    /** @var list<string> */
    private array $urls = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->logger = new RecordingLogger();
    }

    public function test_the_registry_hands_github_pull_requests_to_this_reader(): void
    {
        $readers = self::getContainer()->get(ApprovalCoverageReaders::class);
        self::assertInstanceOf(ApprovalCoverageReaders::class, $readers);

        self::assertInstanceOf(GitHubApprovalCoverageReader::class, $readers->for('github'));
    }

    public function test_one_merge_from_the_base_is_covered(): void
    {
        $pullRequest = $this->tracked('Ubermuda/Loupe', 71_001);
        $this->answers(
            $this->compare('ahead', [$this->commit('b1', 'b0'), $this->commit(self::HEAD, self::COVERED, 'b1')]),
            $this->compare('ahead', [$this->commit('p1', 'b0'), $this->commit(self::COVERED, 'p1'), $this->commit(self::HEAD, self::COVERED, 'b1')]),
        );

        self::assertSame(ApprovalCoverage::Covered, $this->reader()->read($pullRequest));
        self::assertSame([
            'https://api.github.com/repos/Ubermuda/Loupe/compare/'.self::COVERED.'...'.self::HEAD,
            'https://api.github.com/repos/Ubermuda/Loupe/compare/release%2F1.x...'.self::HEAD,
        ], \array_slice($this->urls, 1));
    }

    public function test_two_merges_from_the_base_in_one_push_are_covered(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_002);
        $this->answers(
            $this->compare('ahead', [$this->commit('b1', 'b0'), $this->commit('m1', self::COVERED, 'b1'), $this->commit('b2', 'b1'), $this->commit(self::HEAD, 'm1', 'b2')]),
            $this->compare('ahead', [$this->commit(self::COVERED, 'b0'), $this->commit('m1', self::COVERED, 'b1'), $this->commit(self::HEAD, 'm1', 'b2')]),
        );

        self::assertSame(ApprovalCoverage::Covered, $this->reader()->read($pullRequest));
    }

    public function test_a_merge_that_resolved_a_conflict_is_covered(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_003);
        $this->answers(
            $this->compare('ahead', [$this->commit('b1', 'b0'), $this->commit('b2', 'b1'), $this->commit(self::HEAD, self::COVERED, 'b2')]),
            $this->compare('ahead', [$this->commit(self::COVERED, 'b0'), $this->commit(self::HEAD, self::COVERED, 'b2')]),
        );

        self::assertSame(ApprovalCoverage::Covered, $this->reader()->read($pullRequest));
    }

    public function test_an_identical_head_is_covered(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_004);
        $this->answers($this->compare('identical', []));

        self::assertSame(ApprovalCoverage::Covered, $this->reader()->read($pullRequest));
    }

    public function test_a_content_commit_is_not_covered(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_005);
        $this->answers(
            $this->compare('ahead', [$this->commit(self::HEAD, self::COVERED)]),
            $this->compare('ahead', [$this->commit(self::COVERED, 'b0'), $this->commit(self::HEAD, self::COVERED)]),
        );

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader()->read($pullRequest));
    }

    public function test_a_merge_then_a_content_commit_is_not_covered(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_006);
        $this->answers(
            $this->compare('ahead', [$this->commit('b1', 'b0'), $this->commit('m1', self::COVERED, 'b1'), $this->commit(self::HEAD, 'm1')]),
            $this->compare('ahead', [$this->commit(self::COVERED, 'b0'), $this->commit('m1', self::COVERED, 'b1'), $this->commit(self::HEAD, 'm1')]),
        );

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader()->read($pullRequest));
    }

    public function test_a_merge_of_a_commit_that_is_not_on_the_base_is_not_covered(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_007);
        $this->answers(
            $this->compare('ahead', [$this->commit(self::HEAD, self::COVERED, 'f1')]),
            $this->compare('ahead', [$this->commit('f1', 'b0'), $this->commit(self::COVERED, 'b0'), $this->commit(self::HEAD, self::COVERED, 'f1')]),
        );

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader()->read($pullRequest));
    }

    public function test_a_force_push_is_not_covered(): void
    {
        foreach (['diverged', 'behind'] as $offset => $status) {
            $pullRequest = $this->tracked('ubermuda/loupe', 71_010 + $offset);
            $this->answers($this->compare($status, [$this->commit(self::HEAD, 'b0')]));

            self::assertSame(ApprovalCoverage::NotCovered, $this->reader()->read($pullRequest), $status);
        }
    }

    public function test_a_cut_list_is_not_covered(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_012);
        $this->answers($this->compare('ahead', [$this->commit(self::HEAD, self::COVERED, 'b1')], total: 300));

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader()->read($pullRequest));

        $pullRequest = $this->tracked('ubermuda/loupe', 71_013);
        $this->answers(
            $this->compare('ahead', [$this->commit(self::HEAD, self::COVERED, 'b1')]),
            $this->compare('ahead', [$this->commit(self::HEAD, self::COVERED, 'b1')], total: 300),
        );

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader()->read($pullRequest));
    }

    public function test_a_failed_compare_is_unknown_and_logged(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_014);
        $this->answers(
            $this->compare('ahead', [$this->commit(self::HEAD, self::COVERED, 'b1')]),
            new MockResponse('{"message":"Server Error"}', ['http_code' => 500]),
        );

        self::assertSame(ApprovalCoverage::Unknown, $this->reader()->read($pullRequest));
        self::assertSame('forge.approval_coverage_unreadable', $this->logger->records[0]['message']);
        self::assertSame('http_status', $this->logger->records[0]['context']['reason']);
    }

    public function test_a_malformed_compare_is_unknown(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_015);
        $this->answers($this->answer(['status' => 'ahead', 'total_commits' => 1, 'commits' => [['sha' => self::HEAD, 'parents' => 'none']]]));

        self::assertSame(ApprovalCoverage::Unknown, $this->reader()->read($pullRequest));
        self::assertSame('malformed_body', $this->logger->records[0]['context']['reason']);
    }

    public function test_a_pull_request_without_an_installation_is_unknown(): void
    {
        $project = $this->project();
        $pullRequest = new ForgePullRequest($project, 'github', 'ubermuda/loupe', 604);
        $this->facts($pullRequest);
        $this->em->persist($pullRequest);
        $this->em->flush();

        self::assertSame(ApprovalCoverage::Unknown, $this->reader()->read($pullRequest));
        self::assertSame('no_installation', $this->logger->records[0]['context']['reason']);
        self::assertSame([], $this->urls);
    }

    public function test_a_pull_request_without_the_facts_to_compare_is_unknown(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 71_016);
        $pullRequest->baseBranch = null;

        self::assertSame(ApprovalCoverage::Unknown, $this->reader()->read($pullRequest));
        self::assertSame([], $this->urls);
    }

    private function tracked(string $path, int $installationId): ForgePullRequest
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation($project, $installationId, 'ubermuda', GitHubRepositorySelection::Selected));
        $this->em->persist(new ForgeRepository($project, 'github', (string) $installationId, $path, ForgeRepositorySource::Installation, (string) $installationId));
        $pullRequest = new ForgePullRequest($project, 'github', $path, 604);
        $this->facts($pullRequest);
        $this->em->persist($pullRequest);
        $this->em->flush();

        return $pullRequest;
    }

    private function facts(ForgePullRequest $pullRequest): void
    {
        $pullRequest->baseBranch = 'release/1.x';
        $pullRequest->coveredSha = self::COVERED;
        $pullRequest->headSha = self::HEAD;
    }

    private function project(): Project
    {
        $user = new User(fullName: 'Riley', email: 'coverage-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'coverage-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);

        return $project;
    }

    private function reader(): GitHubApprovalCoverageReader
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->urls[] = $url;

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected request '.$method.' '.$url);
        }, 'https://api.github.com');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);

        $installations = self::getContainer()->get(GitHubPullRequestInstallations::class);
        self::assertInstanceOf(GitHubPullRequestInstallations::class, $installations);

        return new GitHubApprovalCoverageReader(
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, '123456', $pem), new MockClock()),
            $installations,
            $this->logger,
        );
    }

    /** Queues the token answer, then the compare answers in the order the reader asks. */
    private function answers(MockResponse ...$compares): void
    {
        $this->urls = [];
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), ...array_values($compares)];
    }

    /** @param list<array<string, mixed>> $commits */
    private function compare(string $status, array $commits, ?int $total = null): MockResponse
    {
        return $this->answer(['status' => $status, 'total_commits' => $total ?? \count($commits), 'commits' => $commits]);
    }

    /** @return array<string, mixed> */
    private function commit(string $sha, string ...$parents): array
    {
        return ['sha' => $sha, 'parents' => array_map(static fn (string $parent): array => ['sha' => $parent], array_values($parents))];
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
