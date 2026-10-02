<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class WorkRequestResultApiTest extends WebTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-01T12:30:00+00:00';

    public function test_a_done_result_settles_the_claim(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('result-done@example.com');
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $raw = $this->token($owner);
        [$request, $token] = $this->claimed($client, $raw, $project, $bridge);

        $this->put($client, $raw, $this->path($bridge, $request), ['claimToken' => $token, 'state' => 'done']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['workRequestId' => (string) $request->id, 'state' => 'done'], $this->json($client));
        $stored = $this->reload($request);
        self::assertSame(WorkRequestState::Done, $stored->state);
        self::assertNull($stored->reason);
        self::assertSame(self::NOW, $stored->settledAt?->format(\DateTimeInterface::ATOM));

        $payloads = $this->outboxPayloads();
        self::assertSame(['claimed', 'done'], array_column($payloads, 'state'));
        self::assertStringNotContainsString($token, json_encode($payloads, \JSON_THROW_ON_ERROR));

        $record = $audit->record('bridge.work_request_settled');
        self::assertSame((string) $request->id, $record->subject?->id);
        self::assertSame('done', $record->context['state']);
        self::assertStringNotContainsString($token, json_encode($record->context, \JSON_THROW_ON_ERROR));
    }

    public function test_a_refused_result_keeps_its_reason_code(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('result-refused@example.com');
        $raw = $this->token($owner);
        [$request, $token] = $this->claimed($client, $raw, $project, $bridge);

        $this->put($client, $raw, $this->path($bridge, $request), ['claimToken' => $token, 'state' => 'refused', 'reason' => 'worker-busy']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('refused', $this->json($client)['state']);
        $stored = $this->reload($request);
        self::assertSame(WorkRequestState::Refused, $stored->state);
        self::assertSame('worker-busy', $stored->reason);
    }

    /** A bridge that lost the reply can send the same result again. */
    public function test_a_repeated_result_answers_the_same_and_changes_nothing(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('result-repeat@example.com');
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $raw = $this->token($owner);
        [$request, $token] = $this->claimed($client, $raw, $project, $bridge);
        $body = ['claimToken' => $token, 'state' => 'done'];

        $this->put($client, $raw, $this->path($bridge, $request), $body);
        self::assertResponseStatusCodeSame(200);
        $this->clock()->sleep(60);

        $this->put($client, $raw, $this->path($bridge, $request), $body);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['workRequestId' => (string) $request->id, 'state' => 'done'], $this->json($client));
        self::assertSame(self::NOW, $this->reload($request)->settledAt?->format(\DateTimeInterface::ATOM));
        self::assertCount(2, $this->outboxPayloads());
        self::assertCount(1, $audit->records('bridge.work_request_settled'));
    }

    public function test_a_different_result_after_the_settlement_loses(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('result-other@example.com');
        $raw = $this->token($owner);
        [$request, $token] = $this->claimed($client, $raw, $project, $bridge);

        $this->put($client, $raw, $this->path($bridge, $request), ['claimToken' => $token, 'state' => 'done']);
        $this->put($client, $raw, $this->path($bridge, $request), ['claimToken' => $token, 'state' => 'refused']);

        $this->assertRefused($client, 409, 'claim_lost');
        self::assertSame(WorkRequestState::Done, $this->reload($request)->state);
    }

    /** After a lapse and a new claim, the old holder's result must not replace the new holder's work. */
    public function test_a_stale_token_after_a_reopen_and_a_new_claim_loses(): void
    {
        [$client, $owner, $project, $first] = $this->scenario('result-stale@example.com');
        $second = $this->bridgeFor($owner, $project);
        $raw = $this->token($owner);
        [$request, $stale] = $this->claimed($client, $raw, $project, $first);

        $this->clock()->sleep(121);
        $repository = static::getContainer()->get(WorkRequestRepository::class);
        self::assertInstanceOf(WorkRequestRepository::class, $repository);
        self::assertCount(1, $repository->reopenLapsed($this->clock()->now()));
        $this->post($client, $raw, '/api/bridges/'.$second->id.'/work-requests/'.$request->id.'/claim');
        self::assertResponseStatusCodeSame(200);
        $fresh = $this->json($client)['claimToken'];

        $this->put($client, $raw, $this->path($first, $request), ['claimToken' => $stale, 'state' => 'done']);

        $this->assertRefused($client, 409, 'claim_lost');
        $stored = $this->reload($request);
        self::assertSame(WorkRequestState::Claimed, $stored->state);
        self::assertTrue($second->id->equals($stored->bridgeId));
        self::assertSame($fresh, $stored->claimToken?->toRfc4122());

        $this->put($client, $raw, $this->path($second, $request), ['claimToken' => $fresh, 'state' => 'done']);
        self::assertResponseStatusCodeSame(200);
    }

    public function test_the_token_of_another_bridge_loses(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('result-bridge@example.com');
        $other = $this->bridgeFor($owner, $project);
        $raw = $this->token($owner);
        [$request, $token] = $this->claimed($client, $raw, $project, $bridge);

        $this->put($client, $raw, $this->path($other, $request), ['claimToken' => $token, 'state' => 'done']);

        $this->assertRefused($client, 409, 'claim_lost');
        self::assertSame(WorkRequestState::Claimed, $this->reload($request)->state);
    }

    public function test_an_unknown_token_loses(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('result-token@example.com');
        $raw = $this->token($owner);
        [$request] = $this->claimed($client, $raw, $project, $bridge);

        $this->put($client, $raw, $this->path($bridge, $request), ['claimToken' => (string) Uuid::v4(), 'state' => 'done']);

        $this->assertRefused($client, 409, 'claim_lost');
        self::assertSame(WorkRequestState::Claimed, $this->reload($request)->state);
    }

    public function test_a_request_of_another_owner_is_not_found(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('result-owner@example.com');
        [$request, $token] = $this->claimed($client, $this->token($owner), $project, $bridge);
        $stranger = $this->user($this->em(), 'result-owner-stranger@example.com');

        $this->put($client, $this->token($stranger), $this->path($bridge, $request), ['claimToken' => $token, 'state' => 'done']);

        $this->assertRefused($client, 404, 'work_request_not_found');
        self::assertSame(WorkRequestState::Claimed, $this->reload($request)->state);
    }

    public function test_an_unknown_request_is_not_found(): void
    {
        [$client, $owner, , $bridge] = $this->scenario('result-unknown@example.com');

        $this->put($client, $this->token($owner), '/api/bridges/'.$bridge->id.'/work-requests/'.Uuid::v7().'/result', ['claimToken' => (string) Uuid::v4(), 'state' => 'done']);

        $this->assertRefused($client, 404, 'work_request_not_found');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidBodies(): iterable
    {
        $token = '"claimToken":"0d6c7d55-5b2c-4a54-9a5b-7b1b0e7f5e21"';

        yield 'no body' => ['', 'invalid_state'];
        yield 'no state' => ['{'.$token.'}', 'invalid_state'];
        yield 'the claimed state' => ['{'.$token.',"state":"claimed"}', 'invalid_state'];
        yield 'the cancelled state' => ['{'.$token.',"state":"cancelled"}', 'invalid_state'];
        yield 'a state that is not text' => ['{'.$token.',"state":7}', 'invalid_state'];
        yield 'no claim token' => ['{"state":"done"}', 'invalid_claim_token'];
        yield 'a claim token that is not a uuid' => ['{"claimToken":"abc","state":"done"}', 'invalid_claim_token'];
        yield 'a claim token that is not text' => ['{"claimToken":7,"state":"done"}', 'invalid_claim_token'];
        yield 'a reason that is not a code' => ['{'.$token.',"state":"refused","reason":"The worker is busy."}', 'invalid_reason'];
        yield 'a reason that is not text' => ['{'.$token.',"state":"refused","reason":["x"]}', 'invalid_reason'];
        yield 'a reason that is too long' => ['{'.$token.',"state":"refused","reason":"'.str_repeat('a', WorkRequest::MAX_REASON_LENGTH + 1).'"}', 'invalid_reason'];
    }

    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_is_refused_with_a_code(string $body, string $code): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('result-invalid@example.com');
        $raw = $this->token($owner);
        [$request] = $this->claimed($client, $raw, $project, $bridge);

        $this->send($client, $raw, $this->path($bridge, $request), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $code], $this->json($client));
        self::assertSame(WorkRequestState::Claimed, $this->reload($request)->state);
    }

    public function test_a_blank_reason_is_stored_as_none(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('result-blank@example.com');
        $raw = $this->token($owner);
        [$request, $token] = $this->claimed($client, $raw, $project, $bridge);

        $this->put($client, $raw, $this->path($bridge, $request), ['claimToken' => $token, 'state' => 'refused', 'reason' => '  ']);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->reload($request)->reason);
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('result-widget@example.com');
        [$request, $token] = $this->claimed($client, $this->token($owner), $project, $bridge);
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);

        $this->put($client, $raw, $this->path($bridge, $request), ['claimToken' => $token, 'state' => 'done']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(WorkRequestState::Claimed, $this->reload($request)->state);
    }

    /** The bridge reads a 404 with no error code as a server with no result endpoint. */
    public function test_it_is_absent_with_no_error_code_while_push_is_switched_off(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('result-flag@example.com');
        $raw = $this->token($owner);
        [$request, $token] = $this->claimed($client, $raw, $project, $bridge);
        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [AgentPush::FLAG]);

        $this->put($client, $raw, $this->path($bridge, $request), ['claimToken' => $token, 'state' => 'done']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('"error"', (string) $client->getResponse()->getContent());
        self::assertSame(WorkRequestState::Claimed, $this->reload($request)->state);
    }

    public function test_results_have_their_own_limit(): void
    {
        [$client, $owner, $project, $bridge] = $this->scenario('result-limit@example.com');
        static::getContainer()->set('limiter.agent_work_request_results', new RateLimiterFactory(
            ['id' => 'agent_work_request_results', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $raw = $this->token($owner);
        [$request, $token] = $this->claimed($client, $raw, $project, $bridge);
        $body = ['claimToken' => $token, 'state' => 'done'];

        $this->put($client, $raw, $this->path($bridge, $request), $body);
        self::assertResponseStatusCodeSame(200);

        $this->put($client, $raw, $this->path($bridge, $request), $body);
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
        $project = $this->project($em, $owner, 'Result Project');

        return [$client, $owner, $project, $this->bridgeFor($owner, $project)];
    }

    /** @return array{WorkRequest, string} the request and its claim token, claimed through the endpoint */
    private function claimed(KernelBrowser $client, string $raw, Project $project, Bridge $bridge): array
    {
        $request = $this->seedWorkRequest($this->em(), $project);
        $this->post($client, $raw, '/api/bridges/'.$bridge->id.'/work-requests/'.$request->id.'/claim');
        self::assertResponseStatusCodeSame(200);
        $token = $this->json($client)['claimToken'];
        self::assertIsString($token);

        return [$request, $token];
    }

    private function bridgeFor(User $owner, Project $project): Bridge
    {
        $projectId = ($project->id ?? throw new \LogicException('A flushed project has an id.'))->toRfc4122();
        $bridge = $this->seedBridge($this->em(), $owner, projects: [$projectId]);
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS];
        $this->em()->flush();

        return $bridge;
    }

    private function token(User $owner): string
    {
        return AgentCredential::agentToken(static::getContainer(), $owner);
    }

    private function clock(): MockClock
    {
        $clock = static::getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    private function path(Bridge $bridge, WorkRequest $request): string
    {
        return '/api/bridges/'.$bridge->id.'/work-requests/'.$request->id.'/result';
    }

    private function post(KernelBrowser $client, string $raw, string $path): void
    {
        $client->request(Request::METHOD_POST, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'HTTP_ACCEPT' => 'application/json']);
    }

    /** @param array<string, mixed> $body */
    private function put(KernelBrowser $client, string $raw, string $path, array $body): void
    {
        $this->send($client, $raw, $path, json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function send(KernelBrowser $client, string $raw, string $path, string $body): void
    {
        $client->request(
            Request::METHOD_PUT,
            $path,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $body,
        );
    }

    private function assertRefused(KernelBrowser $client, int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame(['error' => $code], $this->json($client));
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
