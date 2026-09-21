<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class ProjectColumnsApiTest extends WebTestCase
{
    use BoardScenario;

    public function test_it_lists_the_columns_with_translated_labels(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'columns-api-list@example.com');
        $project = $this->project($em, $owner, 'Columns App');
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();

        $this->get($client, '/api/projects/'.$project->id.'/board/columns', $raw);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            json_encode([
                'project' => ['id' => (string) $project->id, 'slug' => 'columns-app'],
                'columns' => [
                    ['slug' => 'backlog', 'label' => 'Backlog', 'terminal' => false, 'default' => true],
                    ['slug' => 'next', 'label' => 'Next', 'terminal' => false, 'default' => false],
                    ['slug' => 'in-progress', 'label' => 'In progress', 'terminal' => false, 'default' => false],
                    ['slug' => 'done', 'label' => 'Done', 'terminal' => true, 'default' => false],
                ],
            ], \JSON_THROW_ON_ERROR),
            (string) $client->getResponse()->getContent(),
        );
    }

    public function test_the_columns_follow_board_position_and_a_typed_label_is_kept(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'columns-api-order@example.com');
        $project = $this->project($em, $owner, 'Ordered App');
        foreach (['done' => 0, 'in-progress' => 1, 'next' => 2, 'backlog' => 3] as $slug => $position) {
            $this->column($project, $slug)->position = $position;
        }
        $this->column($project, 'next')->label = 'Ready for review';
        $em->flush();
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();

        $this->get($client, '/api/projects/'.$project->id.'/board/columns', $raw);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(['done', 'in-progress', 'next', 'backlog'], array_column($data['columns'], 'slug'));
        self::assertSame(['Done', 'In progress', 'Ready for review', 'Backlog'], array_column($data['columns'], 'label'));
    }

    public function test_the_handle_can_be_the_project_slug(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'columns-api-slug@example.com');
        $project = $this->project($em, $owner, 'Slugged App');
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();

        $this->get($client, '/api/projects/slugged-app/board/columns', $raw);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(['id' => (string) $project->id, 'slug' => 'slugged-app'], $data['project']);
    }

    public function test_a_project_name_is_not_a_handle(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'columns-api-name@example.com');
        $this->project($em, $owner, 'Named App');
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();

        $this->get($client, '/api/projects/'.rawurlencode('Named App').'/board/columns', $raw);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString(
            '{"error":"project_not_found"}',
            (string) $client->getResponse()->getContent(),
        );
    }

    /** Another owner's project answers exactly as a project that does not exist. */
    public function test_another_users_project_is_indistinguishable_from_an_unknown_one(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $caller = $this->user($em, 'columns-api-caller@example.com');
        $other = $this->project($em, $this->user($em, 'columns-api-other@example.com'), 'Private App');
        $raw = $this->agentToken($client, $caller);
        $this->enableBoard();

        foreach ([(string) $other->id, 'private-app', (string) Uuid::v7()] as $handle) {
            $this->get($client, '/api/projects/'.rawurlencode($handle).'/board/columns', $raw);

            self::assertResponseStatusCodeSame(404);
            self::assertJsonStringEqualsJsonString(
                '{"error":"project_not_found"}',
                (string) $client->getResponse()->getContent(),
                $handle,
            );
        }
    }

    public function test_it_is_absent_while_the_board_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'columns-api-flag@example.com');
        $project = $this->project($em, $owner, 'Flagged App');
        $raw = $this->agentToken($client, $owner);

        $this->get($client, '/api/projects/'.$project->id.'/board/columns', $raw);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString(
            '{"error":"board_disabled"}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'columns-api-widget@example.com');
        $project = $this->project($em, $owner, 'Widget App');
        $raw = AgentCredential::tokenFor(static::getContainer(), $client, $owner, 'site-review', $project);
        $this->enableBoard();

        $this->get($client, '/api/projects/'.$project->id.'/board/columns', $raw);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonStringEqualsJsonString(
            '{"error":"insufficient_scope"}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function test_a_request_without_a_token_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'columns-api-anonymous@example.com'), 'Anonymous App');
        $this->enableBoard();

        $client->request(Request::METHOD_GET, '/api/projects/'.$project->id.'/board/columns');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * With the token unresolved, the listener would key on the address, and the
     * second read below would pass. A 429 proves the firewall ran first.
     */
    public function test_the_limit_counts_per_token_because_the_firewall_runs_first(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_board_columns', new RateLimiterFactory(
            ['id' => 'agent_board_columns', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'columns-api-limit@example.com');
        $project = $this->project($em, $owner, 'Limited App');
        $other = $this->user($em, 'columns-api-limit-other@example.com');
        $otherProject = $this->project($em, $other, 'Other Limited App');
        $first = $this->agentToken($client, $owner);
        // Two access tokens of one grant share a bucket, so the second budget
        // needs a second account.
        $second = $this->agentToken($client, $other);
        $this->enableBoard();
        $path = '/api/projects/'.$project->id.'/board/columns';

        $this->get($client, $path, $first, '203.0.113.7');
        self::assertResponseIsSuccessful();

        $this->get($client, $path, $first, '198.51.100.4');
        self::assertResponseStatusCodeSame(429);

        $this->get($client, '/api/projects/'.$otherProject->id.'/board/columns', $second, '203.0.113.7');
        self::assertResponseIsSuccessful();
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function agentToken(KernelBrowser $browser, User $owner): string
    {
        return AgentCredential::agentToken(static::getContainer(), $browser, $owner);
    }

    private function get(KernelBrowser $client, string $path, string $raw, string $clientIp = '127.0.0.1'): void
    {
        $client->request(Request::METHOD_GET, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'REMOTE_ADDR' => $clientIp]);
    }
}
