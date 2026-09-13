<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
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
        $raw = $this->agentToken($em, $owner);
        $this->enableBoard();

        $this->get($client, '/api/projects/'.$project->id.'/board/columns', $raw);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            json_encode([
                'project' => ['id' => (string) $project->id, 'slug' => null],
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
        $raw = $this->agentToken($em, $owner);
        $this->enableBoard();

        foreach (['done' => 0, 'in-progress' => 1, 'next' => 2, 'backlog' => 3] as $slug => $position) {
            $this->column($project, $slug)->position = $position;
        }
        $this->column($project, 'next')->label = 'Ready for review';
        $em->flush();

        $this->get($client, '/api/projects/'.$project->id.'/board/columns', $raw);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(['done', 'in-progress', 'next', 'backlog'], array_column($data['columns'], 'slug'));
        self::assertSame(['Done', 'In progress', 'Ready for review', 'Backlog'], array_column($data['columns'], 'label'));
    }

    public function test_the_handle_can_be_a_project_name_that_holds_a_slash(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'columns-api-name@example.com');
        $project = $this->project($em, $owner, 'Client/Named App');
        $raw = $this->agentToken($em, $owner);
        $this->enableBoard();

        $this->get($client, '/api/projects/'.rawurlencode('Client/Named App').'/board/columns', $raw);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame((string) $project->id, $data['project']['id']);
    }

    /** Another owner's project answers exactly as a project that does not exist. */
    public function test_another_users_project_is_indistinguishable_from_an_unknown_one(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $caller = $this->user($em, 'columns-api-caller@example.com');
        $raw = $this->agentToken($em, $caller);
        $other = $this->project($em, $this->user($em, 'columns-api-other@example.com'), 'Private App');
        $this->enableBoard();

        foreach ([(string) $other->id, 'Private App', (string) Uuid::v7()] as $handle) {
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
        $raw = $this->agentToken($em, $owner);

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
        [$token, $raw] = ApiToken::issue($owner, 'widget', ApiTokenScope::SiteReview);
        $em->persist($token);
        $project->widgetToken = $token;
        $em->flush();
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
        $first = $this->agentToken($em, $owner);
        $second = $this->agentToken($em, $owner);
        $this->enableBoard();
        $path = '/api/projects/'.$project->id.'/board/columns';

        $this->get($client, $path, $first, '203.0.113.7');
        self::assertResponseIsSuccessful();

        $this->get($client, $path, $first, '198.51.100.4');
        self::assertResponseStatusCodeSame(429);

        $this->get($client, $path, $second, '203.0.113.7');
        self::assertResponseIsSuccessful();
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function agentToken(EntityManagerInterface $em, User $owner): string
    {
        [$token, $raw] = ApiToken::issue($owner, 'bridge', ApiTokenScope::Agent);
        $em->persist($token);
        $em->flush();

        return $raw;
    }

    private function get(KernelBrowser $client, string $path, string $raw, string $clientIp = '127.0.0.1'): void
    {
        $client->request(Request::METHOD_GET, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'REMOTE_ADDR' => $clientIp]);
    }
}
