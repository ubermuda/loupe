<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Forge\ForgeEventType;
use App\Module\Forge\GitHubAdapter;
use App\Module\Forge\InvalidForgeSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(GitHubAdapter::class)]
final class GitHubAdapterTest extends TestCase
{
    private const string SECRET = 'a-shared-secret';

    public function test_it_reads_a_merge_from_a_closed_pull_request(): void
    {
        $deliveries = $this->translate('pull_request', [
            'action' => 'closed',
            'repository' => ['full_name' => 'ubermuda/loupe'],
            'pull_request' => ['number' => 541, 'merged' => true],
        ]);

        self::assertCount(1, $deliveries);
        self::assertSame(ForgeEventType::MERGED, $deliveries[0]->type);
        self::assertSame('ubermuda/loupe', $deliveries[0]->repository);
        self::assertSame(541, $deliveries[0]->number);
        self::assertSame('github', $deliveries[0]->forge);
    }

    /** A pull request closed without merging is not a merge. */
    public function test_a_closed_but_unmerged_pull_request_says_nothing(): void
    {
        self::assertSame([], $this->translate('pull_request', [
            'action' => 'closed',
            'repository' => ['full_name' => 'ubermuda/loupe'],
            'pull_request' => ['number' => 541, 'merged' => false],
        ]));
    }

    /** The event fires on about twenty actions, and the lifecycle uses one. */
    public function test_an_unrelated_pull_request_action_says_nothing(): void
    {
        self::assertSame([], $this->translate('pull_request', [
            'action' => 'labeled',
            'repository' => ['full_name' => 'ubermuda/loupe'],
            'pull_request' => ['number' => 541, 'merged' => false],
        ]));
    }

    public function test_it_reads_a_submitted_review(): void
    {
        $deliveries = $this->translate('pull_request_review', [
            'action' => 'submitted',
            'repository' => ['full_name' => 'ubermuda/loupe'],
            'pull_request' => ['number' => 12],
        ]);

        self::assertCount(1, $deliveries);
        self::assertSame(ForgeEventType::REVIEW_SUBMITTED, $deliveries[0]->type);
        self::assertSame(12, $deliveries[0]->number);
    }

    /** A suite can cover several pull requests, and each one is a delivery. */
    public function test_a_completed_check_suite_covers_every_pull_request_it_names(): void
    {
        $deliveries = $this->translate('check_suite', [
            'action' => 'completed',
            'repository' => ['full_name' => 'ubermuda/loupe'],
            'check_suite' => ['pull_requests' => [['number' => 7], ['number' => 9]]],
        ]);

        self::assertCount(2, $deliveries);
        self::assertSame([7, 9], array_map(static fn ($d): ?int => $d->number, $deliveries));
        self::assertSame(ForgeEventType::CHECKS_CONCLUDED, $deliveries[0]->type);
    }

    /**
     * A verified body can still be shaped wrong. A foreach over a non-array
     * raises a warning, which Symfony turns into a 500 in dev and test, and a
     * 500 makes GitHub retry the delivery for days.
     */
    public function test_a_check_suite_with_a_malformed_pull_request_list_says_nothing(): void
    {
        self::assertSame([], $this->translate('check_suite', [
            'action' => 'completed',
            'repository' => ['full_name' => 'ubermuda/loupe'],
            'check_suite' => ['pull_requests' => 'not-a-list'],
        ]));
    }

    public function test_a_check_suite_still_running_says_nothing(): void
    {
        self::assertSame([], $this->translate('check_suite', [
            'action' => 'requested',
            'repository' => ['full_name' => 'ubermuda/loupe'],
            'check_suite' => ['pull_requests' => [['number' => 7]]],
        ]));
    }

    public function test_it_reads_a_rename_as_the_old_path_and_the_new_one(): void
    {
        $deliveries = $this->translate('repository', [
            'action' => 'renamed',
            'repository' => ['full_name' => 'ubermuda/loupe', 'owner' => ['login' => 'ubermuda']],
            'changes' => ['repository' => ['name' => ['from' => 'loupe-app']]],
        ]);

        self::assertCount(1, $deliveries);
        self::assertSame(ForgeEventType::REPOSITORY_MOVED, $deliveries[0]->type);
        self::assertSame('ubermuda/loupe-app', $deliveries[0]->repository);
        self::assertSame('ubermuda/loupe', $deliveries[0]->movedTo);
        self::assertNull($deliveries[0]->number);
    }

    public function test_a_repository_event_that_is_not_a_rename_says_nothing(): void
    {
        self::assertSame([], $this->translate('repository', [
            'action' => 'publicized',
            'repository' => ['full_name' => 'ubermuda/loupe', 'owner' => ['login' => 'ubermuda']],
        ]));
    }

    public function test_an_event_the_lifecycle_does_not_use_says_nothing(): void
    {
        self::assertSame([], $this->translate('star', ['repository' => ['full_name' => 'ubermuda/loupe']]));
    }

    public function test_it_refuses_a_body_the_signature_does_not_cover(): void
    {
        $this->expectException(InvalidForgeSignature::class);

        $body = json_encode(['action' => 'closed'], \JSON_THROW_ON_ERROR);
        $request = Request::create('/webhooks/forge/github', Request::METHOD_POST, server: [
            'HTTP_X_GITHUB_EVENT' => 'pull_request',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', 'a different body', self::SECRET),
        ], content: $body);

        new GitHubAdapter(self::SECRET)->translate($request);
    }

    /**
     * An instance that never configured a secret must refuse every delivery.
     * Trusting an empty string would accept anything a stranger sends.
     */
    public function test_it_refuses_every_delivery_when_no_secret_is_configured(): void
    {
        $this->expectException(InvalidForgeSignature::class);

        $body = json_encode(['action' => 'closed'], \JSON_THROW_ON_ERROR);
        $request = Request::create('/webhooks/forge/github', Request::METHOD_POST, server: [
            'HTTP_X_GITHUB_EVENT' => 'pull_request',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, ''),
        ], content: $body);

        new GitHubAdapter('')->translate($request);
    }

    public function test_it_refuses_a_verified_body_that_is_not_json(): void
    {
        $this->expectException(InvalidForgeSignature::class);

        $request = Request::create('/webhooks/forge/github', Request::METHOD_POST, server: [
            'HTTP_X_GITHUB_EVENT' => 'pull_request',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', 'not json', self::SECRET),
        ], content: 'not json');

        new GitHubAdapter(self::SECRET)->translate($request);
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<\App\Module\Forge\ForgeDelivery>
     */
    private function translate(string $event, array $payload): array
    {
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);
        $request = Request::create('/webhooks/forge/github', Request::METHOD_POST, server: [
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], content: $body);

        return new GitHubAdapter(self::SECRET)->translate($request);
    }
}
