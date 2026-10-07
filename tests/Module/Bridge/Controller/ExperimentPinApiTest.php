<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Outbox\AgentPush;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class ExperimentPinApiTest extends WebTestCase
{
    use BridgeScenario;

    private const string BODY = '{"candidate":"sonnet","variants":["opus","sonnet"]}';

    public function test_the_first_call_pins_the_candidate_and_the_next_keeps_it(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-new@example.com');
        $project = $this->project($em, $owner, 'Pin Api New');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path((string) $project->id, 'impl-model', (string) Uuid::v7());

        $this->put($client, $path, $raw, self::BODY);

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"variant":"sonnet","switchedFrom":null}', $this->body($client));

        $this->put($client, $path, $raw, '{"candidate":"opus","variants":["opus","sonnet"]}');

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"variant":"sonnet","switchedFrom":null}', $this->body($client));

        $this->put($client, $path, $raw, '{"candidate":"opus","variants":["opus","haiku"]}');

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"variant":"opus","switchedFrom":"sonnet"}', $this->body($client));
        self::assertCount(1, $this->allPins());
    }

    public function test_the_weights_of_the_variants_are_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-weights@example.com');
        $project = $this->project($em, $owner, 'Pin Api Weights');
        $raw = $this->agentToken($client, $owner);

        $this->put(
            $client,
            $this->path((string) $project->id, 'impl-model', (string) Uuid::v7()),
            $raw,
            '{"candidate":"sonnet","variants":["opus","sonnet"],"weights":[1,3]}',
        );

        self::assertResponseStatusCodeSame(200);
        $definitions = $this->allDefinitions();
        self::assertCount(1, $definitions);
        self::assertSame((string) $project->id, (string) $definitions[0]->project->id);
        self::assertSame('impl-model', $definitions[0]->experiment);
        self::assertSame([['name' => 'opus', 'weight' => 1], ['name' => 'sonnet', 'weight' => 3]], $definitions[0]->weights);
    }

    public function test_the_declared_metrics_are_stored_in_their_order(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-metrics@example.com');
        $project = $this->project($em, $owner, 'Pin Api Metrics');
        $raw = $this->agentToken($client, $owner);

        $this->put(
            $client,
            $this->path((string) $project->id, 'impl-model', (string) Uuid::v7()),
            $raw,
            '{"candidate":"sonnet","variants":["opus","sonnet"],"weights":[1,3],"metrics":["merge-rate","cost","no-such-metric"]}',
        );

        self::assertResponseStatusCodeSame(200);
        $definitions = $this->allDefinitions();
        self::assertCount(1, $definitions);
        self::assertSame(['merge-rate', 'cost', 'no-such-metric'], $definitions[0]->metrics);
    }

    /** A metric fault never changes the variant of a run, and never refuses it. */
    public function test_invalid_metrics_still_pin_and_store_no_metrics(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-bad-metrics@example.com');
        $project = $this->project($em, $owner, 'Pin Api Bad Metrics');
        $raw = $this->agentToken($client, $owner);

        $this->put(
            $client,
            $this->path((string) $project->id, 'impl-model', (string) Uuid::v7()),
            $raw,
            '{"candidate":"sonnet","variants":["opus","sonnet"],"weights":[1,3],"metrics":["cost","cost"]}',
        );

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"variant":"sonnet","switchedFrom":null}', $this->body($client));
        $definitions = $this->allDefinitions();
        self::assertCount(1, $definitions);
        self::assertNull($definitions[0]->metrics);
    }

    public function test_numeric_variant_names_are_stored_with_their_names(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-numeric-weights@example.com');
        $project = $this->project($em, $owner, 'Pin Api Numeric Weights');
        $raw = $this->agentToken($client, $owner);

        $this->put(
            $client,
            $this->path((string) $project->id, 'impl-model', (string) Uuid::v7()),
            $raw,
            '{"candidate":"1","variants":["0","1"],"weights":[1,2]}',
        );

        self::assertResponseStatusCodeSame(200);
        $definitions = $this->allDefinitions();
        self::assertCount(1, $definitions);
        self::assertSame([['name' => '0', 'weight' => 1], ['name' => '1', 'weight' => 2]], $definitions[0]->weights);
    }

    /** A weight fault never changes the variant of a run, and never refuses it. */
    public function test_invalid_weights_still_pin_and_store_no_weights(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-bad-weights@example.com');
        $project = $this->project($em, $owner, 'Pin Api Bad Weights');
        $raw = $this->agentToken($client, $owner);

        $this->put(
            $client,
            $this->path((string) $project->id, 'impl-model', (string) Uuid::v7()),
            $raw,
            '{"candidate":"sonnet","variants":["opus","sonnet"],"weights":[1]}',
        );

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"variant":"sonnet","switchedFrom":null}', $this->body($client));
        self::assertCount(1, $this->allPins());
        self::assertSame([], $this->allDefinitions());
    }

    /** An older server must accept a field that a newer bridge adds. */
    public function test_an_unknown_field_in_the_body_is_ignored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-unknown@example.com');
        $project = $this->project($em, $owner, 'Pin Api Unknown');
        $raw = $this->agentToken($client, $owner);

        $this->put(
            $client,
            $this->path((string) $project->id, 'impl-model', (string) Uuid::v7()),
            $raw,
            '{"candidate":"sonnet","variants":["opus","sonnet"],"future":{"a":1}}',
        );

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"variant":"sonnet","switchedFrom":null}', $this->body($client));
        self::assertCount(1, $this->allPins());
    }

    public function test_another_users_project_answers_project_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $caller = $this->user($em, 'pin-api-caller@example.com');
        $other = $this->project($em, $this->user($em, 'pin-api-other@example.com'), 'Pin Api Private');
        $raw = $this->agentToken($client, $caller);

        foreach ([(string) $other->id, (string) Uuid::v7()] as $handle) {
            $this->put($client, $this->path($handle, 'impl-model', (string) Uuid::v7()), $raw, self::BODY);

            self::assertResponseStatusCodeSame(404, $handle);
            self::assertJsonStringEqualsJsonString('{"error":"project_not_found"}', $this->body($client));
        }

        self::assertSame([], $this->allPins());
    }

    public function test_a_card_id_that_is_not_a_uuid_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-card@example.com');
        $project = $this->project($em, $owner, 'Pin Api Card');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path((string) $project->id, 'impl-model', 'not-a-uuid'), $raw, self::BODY);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString('{"error":"invalid_card_id"}', $this->body($client));
        self::assertSame([], $this->allPins());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidExperiments(): iterable
    {
        yield 'upper case' => ['Impl-Model'];
        yield 'a leading dash' => ['-impl'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'a dot' => ['impl.model'];
    }

    #[DataProvider('invalidExperiments')]
    public function test_an_invalid_experiment_name_is_refused(string $experiment): void
    {
        $client = static::createClient();
        $em = $this->em();
        $suffix = md5($experiment);
        $owner = $this->user($em, 'pin-api-experiment-'.$suffix.'@example.com');
        $project = $this->project($em, $owner, 'Pin Api Experiment '.substr($suffix, 0, 6));
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path((string) $project->id, $experiment, (string) Uuid::v7()), $raw, self::BODY);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString('{"error":"invalid_experiment"}', $this->body($client));
        self::assertSame([], $this->allPins());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBodies(): iterable
    {
        yield 'no body' => [''];
        yield 'a list' => ['["opus"]'];
        yield 'a string' => ['"opus"'];
        yield 'no variants' => ['{"candidate":"opus"}'];
        yield 'variants not a list' => ['{"candidate":"opus","variants":{"a":"opus"}}'];
        yield 'no variant' => ['{"candidate":"opus","variants":[]}'];
        yield 'a variant that is not a string' => ['{"candidate":"opus","variants":["opus",7]}'];
        yield 'a variant that breaks the pattern' => ['{"candidate":"opus","variants":["opus","Sonnet"]}'];
        yield 'a repeated variant' => ['{"candidate":"opus","variants":["opus","opus"]}'];
        yield 'too many variants' => [json_encode(['candidate' => 'v0', 'variants' => array_map(static fn (int $i): string => 'v'.$i, range(0, 32))], \JSON_THROW_ON_ERROR)];
        yield 'no candidate' => ['{"variants":["opus","sonnet"]}'];
        yield 'a candidate that is not a string' => ['{"candidate":1,"variants":["opus","sonnet"]}'];
        yield 'a candidate outside the variants' => ['{"candidate":"haiku","variants":["opus","sonnet"]}'];
    }

    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_is_refused(string $body): void
    {
        $client = static::createClient();
        $em = $this->em();
        $suffix = md5($body);
        $owner = $this->user($em, 'pin-api-body-'.$suffix.'@example.com');
        $project = $this->project($em, $owner, 'Pin Api Body '.substr($suffix, 0, 6));
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path((string) $project->id, 'impl-model', (string) Uuid::v7()), $raw, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString('{"error":"invalid_variants"}', $this->body($client));
        self::assertSame([], $this->allPins());
    }

    /** The payload resolver refuses a body it cannot decode before the controller runs. */
    public function test_a_body_that_is_not_json_is_a_bad_request(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-not-json@example.com');
        $project = $this->project($em, $owner, 'Pin Api Not Json');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path((string) $project->id, 'impl-model', (string) Uuid::v7()), $raw, '{"candidate":');

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->allPins());
    }

    public function test_a_body_that_is_not_marked_json_is_unsupported(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-content-type@example.com');
        $project = $this->project($em, $owner, 'Pin Api Content Type');
        $raw = $this->agentToken($client, $owner);

        $client->request(
            Request::METHOD_PUT,
            $this->path((string) $project->id, 'impl-model', (string) Uuid::v7()),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            content: self::BODY,
        );

        self::assertResponseStatusCodeSame(415);
        self::assertStringNotContainsString('"error"', $this->body($client));
        self::assertSame([], $this->allPins());
    }

    public function test_thirty_two_variants_are_accepted(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-many@example.com');
        $project = $this->project($em, $owner, 'Pin Api Many');
        $raw = $this->agentToken($client, $owner);
        $variants = array_map(static fn (int $i): string => 'v'.$i, range(0, 31));

        $this->put(
            $client,
            $this->path((string) $project->id, 'impl-model', (string) Uuid::v7()),
            $raw,
            json_encode(['candidate' => 'v31', 'variants' => $variants], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"variant":"v31","switchedFrom":null}', $this->body($client));
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-widget@example.com');
        $project = $this->project($em, $owner, 'Pin Api Widget');
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);

        $this->put($client, $this->path((string) $project->id, 'impl-model', (string) Uuid::v7()), $raw, self::BODY);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonStringEqualsJsonString('{"error":"insufficient_scope"}', $this->body($client));
        self::assertSame([], $this->allPins());
    }

    /** The bridge reads a 404 with no error code as a server with no pin endpoint. */
    public function test_it_is_absent_with_no_error_code_while_push_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-flag@example.com');
        $project = $this->project($em, $owner, 'Pin Api Flag');
        $raw = $this->agentToken($client, $owner);
        $em->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [AgentPush::FLAG]);

        $this->put($client, $this->path((string) $project->id, 'impl-model', (string) Uuid::v7()), $raw, self::BODY);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('"error"', $this->body($client));
        self::assertSame([], $this->allPins());
    }

    public function test_pins_share_the_run_report_limit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_worker_runs', new RateLimiterFactory(
            ['id' => 'agent_worker_runs', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'pin-api-limit@example.com');
        $project = $this->project($em, $owner, 'Pin Api Limit');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path((string) $project->id, 'impl-model', (string) Uuid::v7());

        $this->put($client, $path, $raw, self::BODY);
        self::assertResponseStatusCodeSame(200);

        $this->put($client, $path, $raw, self::BODY);
        self::assertResponseStatusCodeSame(429);
    }

    private function path(string $handle, string $experiment, string $cardId): string
    {
        return '/api/projects/'.$handle.'/experiments/'.$experiment.'/pins/'.$cardId;
    }

    private function put(KernelBrowser $client, string $path, string $raw, string $body): void
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

    private function body(KernelBrowser $client): string
    {
        return (string) $client->getResponse()->getContent();
    }

    /** @return list<ExperimentPin> */
    private function allPins(): array
    {
        $this->em()->clear();
        /** @var list<ExperimentPin> $pins */
        $pins = $this->em()->createQuery('SELECT p FROM '.ExperimentPin::class.' p')->getResult();

        return $pins;
    }

    /** @return list<ExperimentDefinition> */
    private function allDefinitions(): array
    {
        $this->em()->clear();
        /** @var list<ExperimentDefinition> $definitions */
        $definitions = $this->em()->createQuery('SELECT d FROM '.ExperimentDefinition::class.' d')->getResult();

        return $definitions;
    }
}
