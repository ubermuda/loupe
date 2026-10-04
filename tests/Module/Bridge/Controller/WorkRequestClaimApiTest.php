<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class WorkRequestClaimApiTest extends WebTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-01T12:30:00+00:00';

    public function test_a_claim_holds_the_request_for_the_bridge(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('claim-ok@example.com');
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $request = $this->seedWorkRequest($this->em(), $project);

        $this->post($client, $this->token($owner), $this->path($bridge, $request));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json($client);
        self::assertSame((string) $request->id, $body['workRequestId']);
        self::assertSame('2026-10-01T12:32:00+00:00', $body['leaseUntil']);
        self::assertIsString($body['claimToken']);
        self::assertTrue(Uuid::isValid($body['claimToken']));
        self::assertIsArray($body['workRequest']);
        self::assertSame('claimed', $body['workRequest']['state']);
        self::assertSame((string) $request->id, $body['workRequest']['workRequestId']);

        $stored = $this->reload($request);
        self::assertSame(WorkRequestState::Claimed, $stored->state);
        self::assertTrue($bridge->id->equals($stored->bridgeId));
        self::assertSame($body['claimToken'], $stored->claimToken?->toRfc4122());
        self::assertSame('2026-10-01T12:32:00+00:00', $stored->leaseUntil?->format(\DateTimeInterface::ATOM));
        self::assertSame(1, $stored->claims);

        $payloads = $this->outboxPayloads();
        self::assertCount(1, $payloads);
        self::assertSame('claimed', $payloads[0]['state']);
        self::assertStringNotContainsString($body['claimToken'], json_encode($payloads, \JSON_THROW_ON_ERROR));

        $record = $audit->record('bridge.work_request_claimed');
        self::assertSame((string) $request->id, $record->subject?->id);
        self::assertSame((string) $bridge->id, $record->context['bridgeId']);
        self::assertStringNotContainsString($body['claimToken'], json_encode($record->context, \JSON_THROW_ON_ERROR));
    }

    public function test_a_second_claim_loses(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('claim-twice@example.com');
        $other = $this->bridgeFor($owner, $project);
        $request = $this->seedWorkRequest($this->em(), $project);
        $raw = $this->token($owner);

        $this->post($client, $raw, $this->path($bridge, $request));
        self::assertResponseStatusCodeSame(200);
        $token = $this->json($client)['claimToken'];

        $this->post($client, $raw, $this->path($other, $request));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'already_claimed'], $this->json($client));
        $stored = $this->reload($request);
        self::assertTrue($bridge->id->equals($stored->bridgeId));
        self::assertSame($token, $stored->claimToken?->toRfc4122());
        self::assertCount(1, $this->outboxPayloads());
    }

    /** The stranger has a bridge of the same id, so the check that refuses is the one on the project owner. */
    public function test_a_request_of_another_owner_is_not_found(): void
    {
        [$client, , $project, $bridge] = $this->scenario('claim-owner@example.com');
        $stranger = $this->user($this->em(), 'claim-owner-stranger@example.com');
        $this->capable($this->seedBridge($this->em(), $stranger, id: $bridge->id, projects: [$this->projectId($project)]));
        $request = $this->seedWorkRequest($this->em(), $project);

        $this->post($client, $this->token($stranger), $this->path($bridge, $request));

        $this->assertRefused($client, 404, 'work_request_not_found');
        self::assertSame(WorkRequestState::Open, $this->reload($request)->state);
    }

    public function test_a_request_of_a_project_the_bridge_does_not_follow_is_not_found(): void
    {
        [$client, $owner, $project] = $this->scenario('claim-unfollowed@example.com');
        $elsewhere = $this->bridgeFor($owner, $this->project($this->em(), $owner, 'Other Project'));
        $request = $this->seedWorkRequest($this->em(), $project);

        $this->post($client, $this->token($owner), $this->path($elsewhere, $request));

        $this->assertRefused($client, 404, 'work_request_not_found');
        self::assertSame(WorkRequestState::Open, $this->reload($request)->state);
    }

    public function test_an_unknown_request_is_not_found(): void
    {
        [$client, $owner, , $bridge] = $this->scenario('claim-unknown@example.com');

        $this->post($client, $this->token($owner), '/api/bridges/'.$bridge->id.'/work-requests/'.Uuid::v7().'/claim');

        $this->assertRefused($client, 404, 'work_request_not_found');
    }

    public function test_an_unknown_bridge_is_refused(): void
    {
        [$client, $owner, $project] = $this->scenario('claim-no-bridge@example.com');
        $request = $this->seedWorkRequest($this->em(), $project);

        $this->post($client, $this->token($owner), '/api/bridges/'.Uuid::v4().'/work-requests/'.$request->id.'/claim');

        $this->assertRefused($client, 422, 'unknown_bridge');
        self::assertSame(WorkRequestState::Open, $this->reload($request)->state);
    }

    public function test_a_bridge_without_the_needed_capability_is_refused(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('claim-capability@example.com');
        $request = $this->seedWorkRequest($this->em(), $project, capability: 'gpu');

        $this->post($client, $this->token($owner), $this->path($bridge, $request));

        $this->assertRefused($client, 422, 'capability_missing');
        self::assertSame(WorkRequestState::Open, $this->reload($request)->state);
    }

    public function test_a_bridge_that_takes_no_work_requests_is_refused(): void
    {
        [$client, $owner, $project] = $this->scenario('claim-no-work@example.com');
        $bridge = $this->seedBridge($this->em(), $owner, projects: [$this->projectId($project)]);
        $request = $this->seedWorkRequest($this->em(), $project);

        $this->post($client, $this->token($owner), $this->path($bridge, $request));

        $this->assertRefused($client, 422, 'capability_missing');
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('claim-widget@example.com');
        $request = $this->seedWorkRequest($this->em(), $project);
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);

        $this->post($client, $raw, $this->path($bridge, $request));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(WorkRequestState::Open, $this->reload($request)->state);
    }

    /** The bridge reads a 404 with no error code as a server with no claim endpoint. */
    public function test_it_is_absent_with_no_error_code_while_push_is_switched_off(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('claim-flag@example.com');
        $request = $this->seedWorkRequest($this->em(), $project);
        $raw = $this->token($owner);
        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [AgentPush::FLAG]);

        $this->post($client, $raw, $this->path($bridge, $request));

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('"error"', (string) $client->getResponse()->getContent());
        self::assertSame(WorkRequestState::Open, $this->reload($request)->state);
    }

    public function test_claims_have_their_own_limit(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('claim-limit@example.com');
        static::getContainer()->set('limiter.agent_work_request_claims', new RateLimiterFactory(
            ['id' => 'agent_work_request_claims', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $request = $this->seedWorkRequest($this->em(), $project);
        $raw = $this->token($owner);

        $this->post($client, $raw, $this->path($bridge, $request));
        self::assertResponseStatusCodeSame(200);

        $this->post($client, $raw, $this->path($bridge, $request));
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{KernelBrowser, User, Project, Bridge}
     */
    private function scenario(string $email): array
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();
        $owner = $this->user($em, $email);
        $project = $this->project($em, $owner, 'Claim Project');

        return [$client, $owner, $project, $this->bridgeFor($owner, $project)];
    }

    private function bridgeFor(User $owner, Project $project): Bridge
    {
        $bridge = $this->seedBridge($this->em(), $owner, projects: [$this->projectId($project)]);
        $this->capable($bridge);

        return $bridge;
    }

    private function capable(Bridge $bridge): void
    {
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS];
        $this->em()->flush();
    }

    private function projectId(Project $project): string
    {
        return ($project->id ?? throw new \LogicException('A flushed project has an id.'))->toRfc4122();
    }

    private function token(User $owner): string
    {
        return AgentCredential::agentToken(static::getContainer(), $owner);
    }

    private function path(Bridge $bridge, WorkRequest $request): string
    {
        return '/api/bridges/'.$bridge->id.'/work-requests/'.$request->id.'/claim';
    }

    private function post(KernelBrowser $client, string $raw, string $path): void
    {
        $client->request(
            Request::METHOD_POST,
            $path,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'HTTP_ACCEPT' => 'application/json'],
        );
    }

    private function assertRefused(KernelBrowser $client, int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame(['error' => $code], $this->json($client));
        self::assertSame([], $this->outboxPayloads());
    }

    /** @return array<mixed> */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function reload(WorkRequest $request): WorkRequest
    {
        $em = $this->em();
        $em->clear();
        $stored = $em->find(WorkRequest::class, $request->id);
        self::assertInstanceOf(WorkRequest::class, $stored);

        return $stored;
    }

    /** @return list<array<string, mixed>> */
    private function outboxPayloads(): array
    {
        /** @var list<string> $payloads */
        $payloads = $this->em()->getConnection()->fetchFirstColumn(
            "SELECT payload FROM outbox_events WHERE type = 'bridge.work_request' ORDER BY sequence",
        );

        return array_map(static fn (string $payload): array => json_decode($payload, true, flags: \JSON_THROW_ON_ERROR), $payloads);
    }
}
