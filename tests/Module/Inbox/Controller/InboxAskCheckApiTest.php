<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class InboxAskCheckApiTest extends WebTestCase
{
    use InboxScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_a_closed_ask_with_every_item_read_reads_all_read(): void
    {
        [$owner, $project] = $this->ownerAndProject('ask-check-all-read');
        $ask = $this->closedAskReadUpTo($project, 2);
        $this->setInboxFlag(true);

        $this->check($this->agentToken($owner), (string) $project->id, (string) $ask->id);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['askId' => (string) $ask->id, 'closed' => true, 'allRead' => true], $this->body());
    }

    public function test_a_closed_ask_with_an_unread_item_is_not_all_read(): void
    {
        [$owner, $project] = $this->ownerAndProject('ask-check-some-read');
        $ask = $this->closedAskReadUpTo($project, 1);
        $this->setInboxFlag(true);

        $this->check($this->agentToken($owner), (string) $project->id, (string) $ask->id);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['askId' => (string) $ask->id, 'closed' => true, 'allRead' => false], $this->body());
    }

    public function test_an_open_ask_is_neither_closed_nor_read(): void
    {
        [$owner, $project] = $this->ownerAndProject('ask-check-open');
        $ask = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1)]);
        $this->setInboxFlag(true);
        self::assertNotNull($project->slug);

        $this->check($this->agentToken($owner), $project->slug, (string) $ask->id);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['askId' => (string) $ask->id, 'closed' => false, 'allRead' => false], $this->body());
    }

    public function test_an_ask_of_another_project_is_not_found(): void
    {
        [$owner, $project] = $this->ownerAndProject('ask-check-mine');
        $otherProject = $this->inboxProject($this->em, $owner);
        $ask = $this->closedAskReadUpTo($otherProject, 1);
        $this->setInboxFlag(true);

        $this->check($this->agentToken($owner), (string) $project->id, (string) $ask->id);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'ask_not_found'], $this->body());
    }

    public function test_an_unknown_ask_is_not_found(): void
    {
        [$owner, $project] = $this->ownerAndProject('ask-check-unknown');
        $this->setInboxFlag(true);

        $this->check($this->agentToken($owner), (string) $project->id, (string) Uuid::v4());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'ask_not_found'], $this->body());
    }

    public function test_another_users_project_is_not_found(): void
    {
        [, $theirProject] = $this->ownerAndProject('ask-check-theirs');
        $ask = $this->closedAskReadUpTo($theirProject, 1);
        $caller = $this->signedUpUser($this->em, 'ask-check-caller');
        $this->setInboxFlag(true);

        $this->check($this->agentToken($caller), (string) $theirProject->id, (string) $ask->id);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'project_not_found'], $this->body());
    }

    public function test_it_is_absent_while_the_inbox_is_switched_off(): void
    {
        [$owner, $project] = $this->ownerAndProject('ask-check-flag-off');
        $ask = $this->closedAskReadUpTo($project, 1);
        $this->setInboxFlag(false);

        $this->check($this->agentToken($owner), (string) $project->id, (string) $ask->id);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_request_without_a_token_is_refused(): void
    {
        [, $project] = $this->ownerAndProject('ask-check-anonymous');
        $ask = $this->closedAskReadUpTo($project, 1);
        $this->setInboxFlag(true);

        $this->client->request(Request::METHOD_GET, $this->path((string) $project->id, (string) $ask->id), server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        [$owner, $project] = $this->ownerAndProject('ask-check-widget');
        $ask = $this->closedAskReadUpTo($project, 1);
        [$token, $raw] = ApiToken::issue($owner, 'widget', ApiTokenScope::SiteReview);
        $this->em->persist($token);
        $project->widgetToken = $token;
        $this->em->flush();
        $this->setInboxFlag(true);

        $this->check($raw, (string) $project->id, (string) $ask->id);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'insufficient_scope'], $this->body());
    }

    public function test_an_mcp_token_is_refused_by_the_firewall(): void
    {
        [$owner, $project] = $this->ownerAndProject('ask-check-mcp');
        $ask = $this->closedAskReadUpTo($project, 1);
        [$token, $raw] = ApiToken::issue($owner, 'mcp', ApiTokenScope::Mcp);
        $this->em->persist($token);
        $project->mcpToken = $token;
        $this->em->flush();
        $this->setInboxFlag(true);

        $this->check($raw, (string) $project->id, (string) $ask->id);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * With the token unresolved, the listener would key on the address, and the
     * second check below would pass. A 429 proves the firewall ran first.
     */
    public function test_the_limit_counts_per_token_because_the_firewall_runs_first(): void
    {
        $this->client->disableReboot();
        static::getContainer()->set('limiter.agent_inbox_ask_checks', new RateLimiterFactory(
            ['id' => 'agent_inbox_ask_checks', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        [$owner, $project] = $this->ownerAndProject('ask-check-limit');
        $ask = $this->closedAskReadUpTo($project, 1);
        $first = $this->agentToken($owner);
        $second = $this->agentToken($owner);
        $this->setInboxFlag(true);

        $this->check($first, (string) $project->id, (string) $ask->id, '203.0.113.7');
        self::assertResponseStatusCodeSame(200);

        $this->check($first, (string) $project->id, (string) $ask->id, '198.51.100.4');
        self::assertResponseStatusCodeSame(429);

        $this->check($second, (string) $project->id, (string) $ask->id, '203.0.113.7');
        self::assertResponseStatusCodeSame(200);
    }

    /** @return array{User, Project} */
    private function ownerAndProject(string $slug): array
    {
        $owner = $this->signedUpUser($this->em, $slug);

        return [$owner, $this->inboxProject($this->em, $owner)];
    }

    /** A closed ask of two answered questions, the first $read of them read by its session. */
    private function closedAskReadUpTo(Project $project, int $read): InboxAsk
    {
        $first = $this->answered($this->em, $this->question($this->em, $project, 1));
        $second = $this->answered($this->em, $this->question($this->em, $project, 2));
        $ask = $this->askHolding($this->em, $project, [$first, $second], closedAt: new \DateTimeImmutable());
        foreach (\array_slice($ask->items->toArray(), 0, $read) as $link) {
            $link->readAt = new \DateTimeImmutable();
        }
        $this->em->flush();

        return $ask;
    }

    private function agentToken(User $owner): string
    {
        [$token, $raw] = ApiToken::issue($owner, 'bridge', ApiTokenScope::Agent);
        $this->em->persist($token);
        $this->em->flush();

        return $raw;
    }

    private function path(string $handle, string $askId): string
    {
        return '/api/projects/'.rawurlencode($handle).'/inbox/asks/'.$askId;
    }

    private function check(string $raw, string $handle, string $askId, string $ip = '127.0.0.1'): void
    {
        $this->client->request(
            Request::METHOD_GET,
            $this->path($handle, $askId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $ip],
        );
    }

    /** @return array<mixed> */
    private function body(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
