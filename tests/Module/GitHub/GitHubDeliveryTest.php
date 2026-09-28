<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub;

use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\ForgeDelivery;
use App\Module\Forge\ForgeEventType;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\InvalidGitHubDelivery;
use App\Module\GitHub\PullRequestRefreshHint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(GitHubDelivery::class)]
final class GitHubDeliveryTest extends TestCase
{
    private const string SECRET = 'a-shared-secret';

    private const array REPOSITORY = ['id' => 4242, 'full_name' => 'ubermuda/loupe'];

    public function test_it_reads_a_merge_from_a_closed_pull_request(): void
    {
        $deliveries = $this->delivery('pull_request', [
            'action' => 'closed',
            'repository' => self::REPOSITORY,
            'pull_request' => ['number' => 541, 'merged' => true],
        ])->forgeDeliveries();

        self::assertCount(1, $deliveries);
        self::assertSame(ForgeEventType::MERGED, $deliveries[0]->type);
        self::assertSame('ubermuda/loupe', $deliveries[0]->repository);
        self::assertSame(541, $deliveries[0]->number);
        self::assertSame('github', $deliveries[0]->forge);
    }

    /** A pull request closed without merging is not a merge. */
    public function test_a_closed_but_unmerged_pull_request_says_nothing(): void
    {
        self::assertSame([], $this->delivery('pull_request', [
            'action' => 'closed',
            'repository' => self::REPOSITORY,
            'pull_request' => ['number' => 541, 'merged' => false],
        ])->forgeDeliveries());
    }

    /** The event fires on about twenty actions, and the lifecycle uses one. */
    public function test_an_unrelated_pull_request_action_says_nothing(): void
    {
        self::assertSame([], $this->delivery('pull_request', [
            'action' => 'labeled',
            'repository' => self::REPOSITORY,
            'pull_request' => ['number' => 541, 'merged' => false],
        ])->forgeDeliveries());
    }

    public function test_it_reads_a_submitted_review(): void
    {
        $deliveries = $this->delivery('pull_request_review', [
            'action' => 'submitted',
            'repository' => self::REPOSITORY,
            'pull_request' => ['number' => 12],
        ])->forgeDeliveries();

        self::assertCount(1, $deliveries);
        self::assertSame(ForgeEventType::REVIEW_SUBMITTED, $deliveries[0]->type);
        self::assertSame(12, $deliveries[0]->number);
    }

    /** A suite can cover several pull requests, and each one is a delivery. */
    public function test_a_completed_check_suite_covers_every_pull_request_it_names(): void
    {
        $deliveries = $this->delivery('check_suite', [
            'action' => 'completed',
            'repository' => self::REPOSITORY,
            'check_suite' => ['pull_requests' => [['number' => 7], ['number' => 9]]],
        ])->forgeDeliveries();

        self::assertCount(2, $deliveries);
        self::assertSame([7, 9], array_map(static fn (ForgeDelivery $d): ?int => $d->number, $deliveries));
        self::assertSame(ForgeEventType::CHECKS_CONCLUDED, $deliveries[0]->type);
    }

    /**
     * A verified body can still be shaped wrong. A foreach over a non-array
     * raises a warning, which Symfony turns into a 500 in dev and test, and a
     * 500 makes GitHub retry the delivery for days.
     */
    public function test_a_check_suite_with_a_malformed_pull_request_list_says_nothing(): void
    {
        self::assertSame([], $this->delivery('check_suite', [
            'action' => 'completed',
            'repository' => self::REPOSITORY,
            'check_suite' => ['pull_requests' => 'not-a-list'],
        ])->forgeDeliveries());
    }

    public function test_a_check_suite_still_running_says_nothing(): void
    {
        self::assertSame([], $this->delivery('check_suite', [
            'action' => 'requested',
            'repository' => self::REPOSITORY,
            'check_suite' => ['pull_requests' => [['number' => 7]]],
        ])->forgeDeliveries());
    }

    /** A move is read from the ownership record, which keys on the id, so the event itself translates to nothing. */
    public function test_a_repository_event_translates_to_nothing(): void
    {
        self::assertSame([], $this->delivery('repository', [
            'action' => 'renamed',
            'repository' => self::REPOSITORY,
            'changes' => ['repository' => ['name' => ['from' => 'loupe-app']]],
        ])->forgeDeliveries());
    }

    public function test_an_event_the_lifecycle_does_not_use_says_nothing(): void
    {
        self::assertSame([], $this->delivery('star', ['repository' => self::REPOSITORY])->forgeDeliveries());
    }

    public function test_it_reads_the_repository_id_beside_its_path(): void
    {
        $repository = $this->delivery('pull_request', ['repository' => self::REPOSITORY])->repository();

        self::assertNotNull($repository);
        self::assertSame(4242, $repository->id);
        self::assertSame('4242', $repository->externalId());
        self::assertSame('ubermuda/loupe', $repository->fullName);
    }

    public function test_a_repository_without_a_numeric_id_is_no_repository(): void
    {
        self::assertNull($this->delivery('pull_request', ['repository' => ['id' => '4242', 'full_name' => 'ubermuda/loupe']])->repository());
        self::assertNull($this->delivery('pull_request', ['repository' => ['full_name' => 'ubermuda/loupe']])->repository());
        self::assertNull($this->delivery('pull_request', ['repository' => ['id' => 4242, 'full_name' => '']])->repository());
    }

    /** Without a repository nothing can be claimed, so nothing can be announced either. */
    public function test_a_pull_request_without_a_repository_says_nothing(): void
    {
        self::assertSame([], $this->delivery('pull_request', [
            'action' => 'closed',
            'repository' => ['full_name' => 'ubermuda/loupe'],
            'pull_request' => ['number' => 541, 'merged' => true],
        ])->forgeDeliveries());
    }

    public function test_it_reads_the_installation_and_its_repository_lists(): void
    {
        $delivery = $this->delivery('installation_repositories', [
            'action' => 'added',
            'installation' => ['id' => 77, 'account' => ['login' => 'ubermuda']],
            'repository_selection' => 'selected',
            'repositories_added' => [['id' => 1, 'full_name' => 'ubermuda/a'], ['full_name' => 'broken'], 'junk'],
            'repositories_removed' => 'not-a-list',
        ]);

        self::assertSame('installation_repositories', $delivery->event);
        self::assertSame('added', $delivery->action());
        self::assertSame(77, $delivery->installationId());
        self::assertSame(GitHubRepositorySelection::Selected, $delivery->repositorySelection());
        self::assertSame(['ubermuda/a'], array_map(static fn ($r): string => $r->fullName, $delivery->repositories('repositories_added')));
        self::assertSame([], $delivery->repositories('repositories_removed'));
    }

    public function test_the_selection_of_an_installation_event_sits_on_the_installation(): void
    {
        $delivery = $this->delivery('installation', [
            'action' => 'created',
            'installation' => ['id' => 77, 'repository_selection' => 'all'],
        ]);

        self::assertSame(GitHubRepositorySelection::All, $delivery->repositorySelection());
    }

    public function test_a_delivery_without_an_installation_has_no_installation_id(): void
    {
        self::assertNull($this->delivery('pull_request', ['repository' => self::REPOSITORY])->installationId());
        self::assertNull($this->delivery('pull_request', ['installation' => ['id' => 'x']])->installationId());
    }

    public function test_it_refuses_a_body_the_signature_does_not_cover(): void
    {
        $body = json_encode(['action' => 'closed'], \JSON_THROW_ON_ERROR);

        $this->assertRefused(InvalidGitHubDelivery::BAD_SIGNATURE, $this->request('pull_request', $body, 'sha256='.hash_hmac('sha256', 'a different body', self::SECRET)), self::SECRET);
    }

    public function test_it_refuses_a_missing_signature(): void
    {
        $this->assertRefused(InvalidGitHubDelivery::BAD_SIGNATURE, $this->request('pull_request', '{}', null), self::SECRET);
    }

    /**
     * An instance that never configured a secret must refuse every delivery.
     * Trusting an empty string would accept anything a stranger sends.
     */
    public function test_it_refuses_every_delivery_when_no_secret_is_configured(): void
    {
        $body = json_encode(['action' => 'closed'], \JSON_THROW_ON_ERROR);

        $this->assertRefused(InvalidGitHubDelivery::NO_SECRET, $this->request('pull_request', $body, 'sha256='.hash_hmac('sha256', $body, '')), '');
    }

    public function test_it_refuses_a_verified_body_that_is_not_a_json_object(): void
    {
        $this->assertRefused(InvalidGitHubDelivery::BAD_PAYLOAD, $this->request('pull_request', 'not json', 'sha256='.hash_hmac('sha256', 'not json', self::SECRET)), self::SECRET);
    }

    /** @return iterable<string, array{string}> */
    public static function pullRequestActions(): iterable
    {
        foreach (['opened', 'reopened', 'synchronize', 'closed', 'edited', 'ready_for_review', 'converted_to_draft'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('pullRequestActions')]
    public function test_a_pull_request_action_that_changes_its_state_hints_its_number(string $action): void
    {
        self::assertEquals([PullRequestRefreshHint::number(541)], $this->delivery('pull_request', [
            'action' => $action,
            'repository' => self::REPOSITORY,
            'pull_request' => ['number' => 541, 'merged' => false, 'base' => ['ref' => 'main']],
        ])->refreshHints());
    }

    public function test_a_merge_also_hints_its_base_branch(): void
    {
        self::assertEquals(
            [PullRequestRefreshHint::number(541), PullRequestRefreshHint::base('main')],
            $this->delivery('pull_request', [
                'action' => 'closed',
                'repository' => self::REPOSITORY,
                'pull_request' => ['number' => 541, 'merged' => true, 'base' => ['ref' => 'main']],
            ])->refreshHints(),
        );
    }

    public function test_a_pull_request_action_that_changes_no_state_hints_nothing(): void
    {
        self::assertSame([], $this->delivery('pull_request', [
            'action' => 'labeled',
            'repository' => self::REPOSITORY,
            'pull_request' => ['number' => 541],
        ])->refreshHints());
    }

    /** @return iterable<string, array{string, mixed, list<PullRequestRefreshHint>}> */
    public static function reviewActions(): iterable
    {
        yield 'approved' => ['submitted', ['state' => 'approved'], [PullRequestRefreshHint::number(7, PullRequestReview::Approved)]];
        yield 'changes requested' => ['submitted', ['state' => 'changes_requested'], [PullRequestRefreshHint::number(7, PullRequestReview::ChangesRequested)]];
        yield 'upper case' => ['submitted', ['state' => 'CHANGES_REQUESTED'], [PullRequestRefreshHint::number(7, PullRequestReview::ChangesRequested)]];
        yield 'commented' => ['submitted', ['state' => 'commented'], [PullRequestRefreshHint::number(7)]];
        yield 'no review' => ['submitted', null, [PullRequestRefreshHint::number(7)]];
        yield 'dismissed' => ['dismissed', ['state' => 'approved'], [PullRequestRefreshHint::number(7)]];
        yield 'edited' => ['edited', ['state' => 'approved'], []];
        yield 'review id' => ['submitted', ['id' => 80, 'state' => 'changes_requested'], [PullRequestRefreshHint::number(7, PullRequestReview::ChangesRequested, '80')]];
        yield 'review id of a comment' => ['submitted', ['id' => 80, 'state' => 'commented'], [PullRequestRefreshHint::number(7)]];
        yield 'review id not a number' => ['submitted', ['id' => '80', 'state' => 'approved'], [PullRequestRefreshHint::number(7, PullRequestReview::Approved)]];
        yield 'review id not positive' => ['submitted', ['id' => 0, 'state' => 'approved'], [PullRequestRefreshHint::number(7, PullRequestReview::Approved)]];
    }

    /** @param list<PullRequestRefreshHint> $hints */
    #[DataProvider('reviewActions')]
    public function test_a_review_hints_its_pull_request_with_the_verdict_it_submits(string $action, mixed $review, array $hints): void
    {
        self::assertEquals($hints, $this->delivery('pull_request_review', [
            'action' => $action,
            'repository' => self::REPOSITORY,
            'pull_request' => ['number' => 7],
            'review' => $review,
        ])->refreshHints());
    }

    /** @return iterable<string, array{string}> */
    public static function checkEvents(): iterable
    {
        yield 'check suite' => ['check_suite'];
        yield 'check run' => ['check_run'];
    }

    #[DataProvider('checkEvents')]
    public function test_a_check_that_starts_or_completes_hints_its_pull_requests_and_its_head(string $event): void
    {
        $delivery = fn (string $action): GitHubDelivery => $this->delivery($event, [
            'action' => $action,
            'repository' => self::REPOSITORY,
            $event => ['head_sha' => 'abc123', 'pull_requests' => [['number' => 3], ['number' => 'x'], 'junk', ['number' => 4]]],
        ]);

        foreach (['completed', 'created', 'requested', 'rerequested'] as $action) {
            self::assertEquals(
                [PullRequestRefreshHint::number(3), PullRequestRefreshHint::number(4), PullRequestRefreshHint::head('abc123')],
                $delivery($action)->refreshHints(),
                $action,
            );
        }
        self::assertSame([], $delivery('requested_action')->refreshHints());
    }

    public function test_a_check_without_pull_requests_still_hints_its_head(): void
    {
        self::assertEquals([PullRequestRefreshHint::head('abc123')], $this->delivery('check_run', [
            'action' => 'completed',
            'repository' => self::REPOSITORY,
            'check_run' => ['head_sha' => 'abc123', 'pull_requests' => 'junk'],
        ])->refreshHints());
    }

    public function test_a_status_hints_its_commit(): void
    {
        self::assertEquals([PullRequestRefreshHint::head('def456')], $this->delivery('status', [
            'repository' => self::REPOSITORY,
            'sha' => 'def456',
            'state' => 'success',
        ])->refreshHints());
        self::assertSame([], $this->delivery('status', ['repository' => self::REPOSITORY, 'sha' => ''])->refreshHints());
    }

    public function test_a_push_to_a_branch_hints_that_base(): void
    {
        self::assertEquals([PullRequestRefreshHint::base('release/1.x')], $this->delivery('push', [
            'ref' => 'refs/heads/release/1.x',
            'repository' => self::REPOSITORY,
            'deleted' => false,
        ])->refreshHints());
    }

    public function test_a_push_of_a_tag_or_a_deleted_branch_hints_nothing(): void
    {
        self::assertSame([], $this->delivery('push', ['ref' => 'refs/tags/v1.0', 'repository' => self::REPOSITORY])->refreshHints());
        self::assertSame([], $this->delivery('push', ['ref' => 'refs/heads/main', 'deleted' => true, 'repository' => self::REPOSITORY])->refreshHints());
        self::assertSame([], $this->delivery('push', ['ref' => 'refs/heads/', 'repository' => self::REPOSITORY])->refreshHints());
    }

    public function test_repeated_hints_are_given_once(): void
    {
        self::assertEquals([PullRequestRefreshHint::number(3), PullRequestRefreshHint::head('abc123')], $this->delivery('check_suite', [
            'action' => 'completed',
            'repository' => self::REPOSITORY,
            'check_suite' => ['head_sha' => 'abc123', 'pull_requests' => [['number' => 3], ['number' => 3]]],
        ])->refreshHints());
    }

    private function assertRefused(string $reason, Request $request, string $secret): void
    {
        try {
            GitHubDelivery::fromRequest($request, $secret);
            self::fail('The delivery was accepted.');
        } catch (InvalidGitHubDelivery $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    /** @param array<mixed> $payload */
    private function delivery(string $event, array $payload): GitHubDelivery
    {
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);

        return GitHubDelivery::fromRequest($this->request($event, $body, 'sha256='.hash_hmac('sha256', $body, self::SECRET)), self::SECRET);
    }

    private function request(string $event, string $body, ?string $signature): Request
    {
        $server = ['HTTP_X_GITHUB_EVENT' => $event];
        if (null !== $signature) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        }

        return Request::create('/webhooks/forge/github', Request::METHOD_POST, server: $server, content: $body);
    }
}
