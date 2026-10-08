<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestReviewFailed;
use App\Module\Forge\Service\PullRequestReviewKind;
use App\Module\Forge\Service\PullRequestReviewPosters;
use App\Module\GitHub\Entity\GitHubUserConnection;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubPullRequestReviewPoster;
use App\Module\GitHub\Service\GitHubUserApi;
use App\Module\GitHub\Service\GitHubUserTokenRefresher;
use App\Module\Project\Entity\Project;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubPullRequestReviewPosterTest extends KernelTestCase
{
    private const string NOW = '2026-10-08 12:00:00';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_the_registry_hands_github_pull_requests_to_this_poster(): void
    {
        $poster = $this->poster();
        $posters = new PullRequestReviewPosters([$poster]);

        self::assertSame($poster, $posters->for('github'));
        self::assertNull($posters->for('gitlab'));
    }

    /** @return iterable<string, array{PullRequestReviewKind, string}> */
    public static function kinds(): iterable
    {
        yield 'approve' => [PullRequestReviewKind::Approve, 'APPROVE'];
        yield 'request changes' => [PullRequestReviewKind::RequestChanges, 'REQUEST_CHANGES'];
        yield 'comment' => [PullRequestReviewKind::Comment, 'COMMENT'];
    }

    #[DataProvider('kinds')]
    public function test_it_posts_the_review_as_the_user_with_the_user_token(PullRequestReviewKind $kind, string $event): void
    {
        $user = $this->connectedUser('review-ok');
        $this->responses = [new MockResponse('{"html_url":"https://github.com/ubermuda/lou%20pe/pull/604#pullrequestreview-9"}')];

        $url = $this->poster()->post($this->pullRequest('ubermuda/lou pe'), $kind, 'Two notes.', (string) $user->id);

        self::assertSame('https://github.com/ubermuda/lou%20pe/pull/604#pullrequestreview-9', $url);
        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://api.github.com/repos/ubermuda/lou%20pe/pulls/604/reviews', $this->requests[0]['url']);
        self::assertContains('Authorization: Bearer the-access-token', $this->requests[0]['options']['headers'] ?? []);
        self::assertSame(['event' => $event, 'body' => 'Two notes.'], $this->sentBody());
    }

    public function test_an_approval_with_no_body_sends_no_body(): void
    {
        $user = $this->connectedUser('review-empty');
        $this->responses = [new MockResponse('{}')];

        $url = $this->poster()->post($this->pullRequest('ubermuda/loupe'), PullRequestReviewKind::Approve, '', (string) $user->id);

        self::assertNull($url);
        self::assertSame(['event' => 'APPROVE'], $this->sentBody());
    }

    public function test_a_long_body_is_cut_to_stay_under_the_github_limit(): void
    {
        $user = $this->connectedUser('review-long');
        $this->responses = [new MockResponse('{}')];

        $this->poster()->post($this->pullRequest('ubermuda/loupe'), PullRequestReviewKind::Comment, str_repeat('é', 70_000), (string) $user->id);

        self::assertSame(60_000, mb_strlen((string) $this->sentBody()['body']));
    }

    public function test_a_user_without_a_connection_is_not_connected(): void
    {
        $user = $this->user('review-none');

        $failure = $this->failure((string) $user->id);

        self::assertSame('not_connected', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->requests);
    }

    public function test_an_unknown_user_id_is_not_connected(): void
    {
        foreach (['not-a-uuid', '01a11b67-3d91-77e0-b45a-a9d121f570fd'] as $userId) {
            $failure = $this->failure($userId);

            self::assertSame('not_connected', $failure->cause);
            self::assertTrue($failure->permanent);
        }
        self::assertSame([], $this->requests);
    }

    public function test_an_expired_connection_is_a_permanent_failure_that_makes_no_call(): void
    {
        $user = $this->connectedUser('review-expired', expired: true);

        $failure = $this->failure((string) $user->id);

        self::assertSame('connection_expired', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->requests);
    }

    /** @return iterable<string, array{int}> */
    public static function refusals(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
        yield '404' => [404];
        yield '422' => [422];
    }

    #[DataProvider('refusals')]
    public function test_a_refused_review_is_a_permanent_permission_failure(int $status): void
    {
        $user = $this->connectedUser('review-refused-'.$status);
        $this->responses = [new MockResponse('{"message":"Unprocessable"}', ['http_code' => $status])];

        $failure = $this->failure((string) $user->id);

        self::assertSame('permission', $failure->cause);
        self::assertTrue($failure->permanent);
    }

    public function test_a_rate_limited_review_is_transient_and_carries_the_delay(): void
    {
        $user = $this->connectedUser('review-limited');
        $this->responses = [new MockResponse('{}', ['http_code' => 403, 'response_headers' => ['retry-after' => '60']])];

        $failure = $this->failure((string) $user->id);

        self::assertSame('api_failed_rate_limited', $failure->cause);
        self::assertFalse($failure->permanent);
        self::assertSame(60, $failure->retryAfterSeconds);
    }

    /** @return iterable<string, array{PullRequestReviewKind}> */
    public static function kindsNeedingABody(): iterable
    {
        yield 'request changes' => [PullRequestReviewKind::RequestChanges];
        yield 'comment' => [PullRequestReviewKind::Comment];
    }

    #[DataProvider('kindsNeedingABody')]
    public function test_a_review_that_needs_a_body_and_has_none_is_refused_before_any_call(PullRequestReviewKind $kind): void
    {
        $user = $this->connectedUser('review-blank-'.$kind->value);

        try {
            $this->poster()->post($this->pullRequest('ubermuda/loupe'), $kind, "  \n", (string) $user->id);
            self::fail('Expected PullRequestReviewFailed.');
        } catch (PullRequestReviewFailed $e) {
            self::assertSame('empty_body', $e->cause);
            self::assertTrue($e->permanent);
        }
        self::assertSame([], $this->requests);
    }

    public function test_a_server_error_is_a_transient_failure_that_names_the_status(): void
    {
        $user = $this->connectedUser('review-502');
        $this->responses = [new MockResponse('{}', ['http_code' => 502])];

        $failure = $this->failure((string) $user->id);

        self::assertSame('api_failed_http_status_502', $failure->cause);
        self::assertFalse($failure->permanent);
    }

    public function test_a_transport_error_is_a_transient_failure(): void
    {
        $user = $this->connectedUser('review-transport');
        $this->responses = [new MockResponse('', ['error' => 'Could not resolve host'])];

        $failure = $this->failure((string) $user->id);

        self::assertSame('api_failed_transport', $failure->cause);
        self::assertFalse($failure->permanent);
    }

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        $body = $this->requests[0]['options']['body'] ?? null;
        self::assertIsString($body);
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function failure(string $userId): PullRequestReviewFailed
    {
        try {
            $this->poster()->post($this->pullRequest('ubermuda/loupe'), PullRequestReviewKind::Comment, 'Hello', $userId);
        } catch (PullRequestReviewFailed $e) {
            return $e;
        }

        self::fail('Expected PullRequestReviewFailed.');
    }

    private function pullRequest(string $path): ForgePullRequest
    {
        $owner = new User(fullName: 'Riley', email: 'review-owner-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($owner, 'review-'.uniqid());
        $this->em->persist($owner);
        $this->em->persist($project);
        $pullRequest = new ForgePullRequest($project, 'github', $path, 604);
        $this->em->persist($pullRequest);
        $this->em->flush();

        return $pullRequest;
    }

    private function poster(): GitHubPullRequestReviewPoster
    {
        $clock = new MockClock(self::NOW);
        $api = new GitHubUserApi(
            new MockHttpClient(),
            new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
                $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return array_shift($this->responses) ?? throw new \LogicException('Unexpected request '.$method.' '.$url);
            }, 'https://api.github.com'),
            new GitHubAppConfiguration('loupe-test', 'the-client-id', 'the-client-secret', 'hook', null, null),
            $clock,
        );
        $connections = self::getContainer()->get(GitHubUserConnectionRepository::class);
        self::assertInstanceOf(GitHubUserConnectionRepository::class, $connections);
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        return new GitHubPullRequestReviewPoster($users, new GitHubUserTokenRefresher($connections, $api, $this->em, $clock, new RecordingLogger()), $api);
    }

    private function connectedUser(string $label, bool $expired = false): User
    {
        $user = $this->user($label);
        $connection = new GitHubUserConnection(
            $user,
            random_int(1, 1_000_000),
            'octocat-'.$label,
            'the-access-token',
            'the-refresh-token',
            new \DateTimeImmutable('2026-10-08 14:00:00'),
            new \DateTimeImmutable('2027-04-08 12:00:00'),
            new \DateTimeImmutable('2026-10-01 09:00:00'),
        );
        if ($expired) {
            $connection->expiredAt = new \DateTimeImmutable('2026-10-07 09:00:00');
        }
        $this->em->persist($connection);
        $this->em->flush();

        return $user;
    }

    private function user(string $label): User
    {
        $user = new User(fullName: 'Riley', email: 'github-review-'.$label.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
