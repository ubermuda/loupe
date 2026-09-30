<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Outbox\AgentPush;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class BridgeCommandAckApiTest extends WebTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-29T12:05:00+00:00';

    public function test_a_done_ack_settles_the_pending_command(): void
    {
        [$client, $owner, $run] = $this->scenario('ack-done@example.com');
        $command = $this->seedCommand($this->em(), $run);

        $this->put($client, $this->token($owner), $this->path($command), '{"state":"done"}');

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString(
            json_encode(['commandId' => (string) $command->id, 'state' => 'done'], \JSON_THROW_ON_ERROR),
            $this->body($client),
        );
        $stored = $this->reload($command);
        self::assertSame(BridgeCommandState::Done, $stored->state);
        self::assertSame(self::NOW, $stored->settledAt?->format(\DateTimeInterface::ATOM));
        self::assertNull($stored->reason);
    }

    public function test_a_refused_ack_settles_the_command_with_the_trimmed_reason(): void
    {
        [$client, $owner, $run] = $this->scenario('ack-refused@example.com');
        $command = $this->seedCommand($this->em(), $run);

        $this->put($client, $this->token($owner), $this->path($command), '{"state":"refused","reason":"  the run already ended  "}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('refused', $this->json($client)['state']);
        $stored = $this->reload($command);
        self::assertSame(BridgeCommandState::Refused, $stored->state);
        self::assertSame('the run already ended', $stored->reason);
    }

    /** A second ack must not move the command again, so a bridge can retry safely. */
    public function test_a_repeated_ack_keeps_the_first_settlement(): void
    {
        [$client, $owner, $run] = $this->scenario('ack-repeat@example.com');
        $command = $this->seedCommand($this->em(), $run);
        $raw = $this->token($owner);

        $this->put($client, $raw, $this->path($command), '{"state":"done"}');
        self::assertResponseStatusCodeSame(200);
        $this->clock()->sleep(60);

        $this->put($client, $raw, $this->path($command), '{"state":"refused","reason":"late"}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('done', $this->json($client)['state']);
        $stored = $this->reload($command);
        self::assertSame(BridgeCommandState::Done, $stored->state);
        self::assertNull($stored->reason);
        self::assertSame(self::NOW, $stored->settledAt?->format(\DateTimeInterface::ATOM));
    }

    public function test_a_command_that_expired_stays_expired(): void
    {
        [$client, $owner, $run] = $this->scenario('ack-expired@example.com');
        $command = $this->seedCommand($this->em(), $run, state: BridgeCommandState::Expired);

        $this->put($client, $this->token($owner), $this->path($command), '{"state":"done"}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('expired', $this->json($client)['state']);
        self::assertSame(BridgeCommandState::Expired, $this->reload($command)->state);
    }

    public function test_another_owners_command_is_not_found(): void
    {
        [$client, , $run] = $this->scenario('ack-owner@example.com');
        $command = $this->seedCommand($this->em(), $run);
        $stranger = $this->user($this->em(), 'ack-owner-stranger@example.com');

        $this->put($client, $this->token($stranger), $this->path($command), '{"state":"done"}');

        $this->assertNotFound($client);
        self::assertSame(BridgeCommandState::Pending, $this->reload($command)->state);
    }

    public function test_a_command_of_another_bridge_is_not_found(): void
    {
        [$client, $owner, $run] = $this->scenario('ack-bridge@example.com');
        $command = $this->seedCommand($this->em(), $run);

        $this->put($client, $this->token($owner), '/api/bridges/'.Uuid::v4().'/commands/'.$command->id, '{"state":"done"}');

        $this->assertNotFound($client);
        self::assertSame(BridgeCommandState::Pending, $this->reload($command)->state);
    }

    public function test_an_unknown_command_is_not_found(): void
    {
        [$client, $owner, $run] = $this->scenario('ack-unknown@example.com');

        $this->put($client, $this->token($owner), '/api/bridges/'.$run->bridgeId.'/commands/'.Uuid::v4(), '{"state":"done"}');

        $this->assertNotFound($client);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidBodies(): iterable
    {
        yield 'no body' => ['', 'invalid_state'];
        yield 'no state' => ['{"reason":"x"}', 'invalid_state'];
        yield 'an unknown state' => ['{"state":"exploded"}', 'invalid_state'];
        yield 'the pending state' => ['{"state":"pending"}', 'invalid_state'];
        yield 'the expired state' => ['{"state":"expired"}', 'invalid_state'];
        yield 'the cancelled state' => ['{"state":"cancelled"}', 'invalid_state'];
        yield 'a state that is not text' => ['{"state":7}', 'invalid_state'];
        yield 'a reason that is not text' => ['{"state":"refused","reason":["x"]}', 'invalid_reason'];
        yield 'a reason that is too long' => [
            json_encode(['state' => 'refused', 'reason' => str_repeat('a', BridgeCommand::MAX_REASON_LENGTH + 1)], \JSON_THROW_ON_ERROR),
            'reason_too_long',
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_is_refused_with_a_code(string $body, string $code): void
    {
        [$client, $owner, $run] = $this->scenario('ack-invalid@example.com');
        $command = $this->seedCommand($this->em(), $run);

        $this->put($client, $this->token($owner), $this->path($command), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $code], $this->json($client));
        self::assertSame(BridgeCommandState::Pending, $this->reload($command)->state);
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        [$client, $owner, $run] = $this->scenario('ack-widget@example.com');
        $command = $this->seedCommand($this->em(), $run);
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $run->project);

        $this->put($client, $raw, $this->path($command), '{"state":"done"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(BridgeCommandState::Pending, $this->reload($command)->state);
    }

    /** The bridge reads a 404 with no error code as a server with no ack endpoint. */
    public function test_it_is_absent_with_no_error_code_while_push_is_switched_off(): void
    {
        [$client, $owner, $run] = $this->scenario('ack-flag@example.com');
        $command = $this->seedCommand($this->em(), $run);
        $raw = $this->token($owner);
        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [AgentPush::FLAG]);

        $this->put($client, $raw, $this->path($command), '{"state":"done"}');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('"error"', $this->body($client));
        self::assertSame(BridgeCommandState::Pending, $this->reload($command)->state);
    }

    public function test_acks_share_the_run_report_limit(): void
    {
        [$client, $owner, $run] = $this->scenario('ack-limit@example.com');
        static::getContainer()->set('limiter.agent_worker_runs', new RateLimiterFactory(
            ['id' => 'agent_worker_runs', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $command = $this->seedCommand($this->em(), $run);
        $raw = $this->token($owner);

        $this->put($client, $raw, $this->path($command), '{"state":"done"}');
        self::assertResponseStatusCodeSame(200);

        $this->put($client, $raw, $this->path($command), '{"state":"done"}');
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{KernelBrowser, User, WorkerRun}
     */
    private function scenario(string $email): array
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();
        $owner = $this->user($em, $email);
        $project = $this->project($em, $owner, 'Ack Project');
        $run = $this->seedRun($em, $project, bridgeId: Uuid::v4());

        return [$client, $owner, $run];
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

    private function path(BridgeCommand $command): string
    {
        return '/api/bridges/'.$command->bridgeId.'/commands/'.$command->id;
    }

    private function put(KernelBrowser $client, string $raw, string $path, string $body): void
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

    private function assertNotFound(KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'command_not_found'], $this->json($client));
    }

    private function body(KernelBrowser $client): string
    {
        return (string) $client->getResponse()->getContent();
    }

    /** @return array<mixed> */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode($this->body($client), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function reload(BridgeCommand $command): BridgeCommand
    {
        $em = $this->em();
        $em->clear();
        $stored = $em->find(BridgeCommand::class, $command->id);
        self::assertInstanceOf(BridgeCommand::class, $stored);

        return $stored;
    }
}
