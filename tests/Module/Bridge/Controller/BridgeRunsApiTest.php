<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Outbox\AgentPush;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class BridgeRunsApiTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_inventory_marks_the_runs_it_does_not_name_lost(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'bridge-runs-lost@example.com');
        $project = $this->project($em, $owner, 'Bridge Runs Lost');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = Uuid::v4();
        $held = $this->seedRun($em, $project, bridgeId: $bridgeId, state: WorkerRunState::TimedOut, runKey: Uuid::v4());
        $gone = $this->seedRun($em, $project, bridgeId: $bridgeId, state: WorkerRunState::Queued, runKey: Uuid::v4());

        $this->put($client, (string) $bridgeId, $raw, ['runs' => [
            ['runId' => (string) $held->runKey, 'projectId' => (string) $project->id, 'state' => 'resumed'],
        ]]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(WorkerRunState::Resumed, $this->reload($held)->state);
        self::assertSame(WorkerRunState::Lost, $this->reload($gone)->state);
    }

    public function test_an_empty_inventory_is_accepted(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'bridge-runs-empty@example.com');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, (string) Uuid::v4(), $raw, ['runs' => []]);

        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        $run = ['runId' => (string) Uuid::v4(), 'projectId' => (string) Uuid::v4(), 'state' => 'queued'];

        yield 'no runs key' => [[]];
        yield 'a run id that is not a uuid' => [['runs' => [array_merge($run, ['runId' => 'nope'])]]];
        yield 'a project id that is not a uuid' => [['runs' => [array_merge($run, ['projectId' => 'nope'])]]];
        yield 'a closed state' => [['runs' => [array_merge($run, ['state' => 'succeeded'])]]];
        yield 'a missing state' => [['runs' => [array_merge($run, ['state' => null])]]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_an_invalid_inventory_is_refused(array $payload): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'bridge-runs-invalid-'.md5(serialize($payload)).'@example.com');
        $project = $this->project($em, $owner, 'Bridge Runs Invalid '.substr(md5(serialize($payload)), 0, 6));
        $raw = $this->agentToken($client, $owner);
        $bridgeId = Uuid::v4();
        $run = $this->seedRun($em, $project, bridgeId: $bridgeId, state: WorkerRunState::Queued, runKey: Uuid::v4());

        $this->put($client, (string) $bridgeId, $raw, $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(WorkerRunState::Queued, $this->reload($run)->state);
    }

    public function test_it_is_absent_while_push_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'bridge-runs-flag@example.com');
        $raw = $this->agentToken($client, $owner);
        $em->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [AgentPush::FLAG]);

        $this->put($client, (string) Uuid::v4(), $raw, ['runs' => []]);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_mcp_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'bridge-runs-mcp@example.com');
        $project = $this->project($em, $owner, 'Bridge Runs Mcp');
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'mcp', $project);

        $this->put($client, (string) Uuid::v4(), $raw, ['runs' => []]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function put(KernelBrowser $client, string $bridgeId, string $raw, array $payload): void
    {
        $client->request(
            Request::METHOD_PUT,
            '/api/bridges/'.$bridgeId.'/runs',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function reload(WorkerRun $run): WorkerRun
    {
        $this->em()->clear();
        $reloaded = $this->em()->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $reloaded);

        return $reloaded;
    }
}
