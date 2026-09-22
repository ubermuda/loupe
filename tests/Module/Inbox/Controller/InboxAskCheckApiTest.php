<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Support\AgentCredential;
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

    public function test_an_open_ask_with_no_item_is_not_all_read(): void
    {
        [$owner, $project] = $this->ownerAndProject('ask-check-open-empty');
        $ask = $this->askHolding($this->em, $project, []);
        $this->setInboxFlag(true);

        $this->check($this->agentToken($owner), (string) $project->id, (string) $ask->id);

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
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);
        $this->setInboxFlag(true);

        $this->check($raw, (string) $project->id, (string) $ask->id);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'insufficient_scope'], $this->body());
    }

    public function test_an_mcp_token_is_refused_by_the_firewall(): void
    {
        [$owner, $project] = $this->ownerAndProject('ask-check-mcp');
        $ask = $this->closedAskReadUpTo($project, 1);
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'mcp', $project);
        $this->setInboxFlag(true);

        $this->check($raw, (string) $project->id, (string) $ask->id);

        self::assertResponseStatusCodeSame(403);
    }

    /** The shipped limiter takes 60 checks from one token in a minute and refuses the 61st. */
    public function test_the_shipped_limit_refuses_the_sixty_first_check_of_a_token(): void
    {
        $this->client->disableReboot();
        [$owner, $project] = $this->ownerAndProject('ask-check-capacity');
        $ask = $this->closedAskReadUpTo($project, 1);
        $raw = $this->agentToken($owner);
        $this->setInboxFlag(true);

        for ($check = 1; $check <= 60; ++$check) {
            $this->check($raw, (string) $project->id, (string) $ask->id);
            self::assertResponseStatusCodeSame(200, 'check '.$check);
        }

        $this->check($raw, (string) $project->id, (string) $ask->id);
        self::assertResponseStatusCodeSame(429);
    }

    /** The limit runs before the handler, so checks of unknown asks still spend the budget. */
    public function test_the_limit_answers_before_an_unknown_ask_is_looked_up(): void
    {
        $this->client->disableReboot();
        static::getContainer()->set('limiter.agent_inbox_ask_checks', new RateLimiterFactory(
            ['id' => 'agent_inbox_ask_checks', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        [$owner, $project] = $this->ownerAndProject('ask-check-limit-order');
        $raw = $this->agentToken($owner);
        $this->setInboxFlag(true);

        $this->check($raw, (string) $project->id, (string) Uuid::v4());
        self::assertResponseStatusCodeSame(404);

        $this->check($raw, (string) $project->id, (string) Uuid::v4());
        self::assertResponseStatusCodeSame(429);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    /**
     * With the token unresolved, the key would be the address, and the second
     * check below would pass. A 429 proves the firewall ran first.
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
        [$other, $otherProject] = $this->ownerAndProject('ask-check-limit-other');
        $otherAsk = $this->closedAskReadUpTo($otherProject, 1);
        $first = $this->agentToken($owner);
        // Two access tokens of one grant share a bucket, so the second budget
        // needs a second account.
        $second = $this->agentToken($other);
        $this->setInboxFlag(true);

        $this->check($first, (string) $project->id, (string) $ask->id, '203.0.113.7');
        self::assertResponseStatusCodeSame(200);

        $this->check($first, (string) $project->id, (string) $ask->id, '198.51.100.4');
        self::assertResponseStatusCodeSame(429);

        $this->check($second, (string) $otherProject->id, (string) $otherAsk->id, '203.0.113.7');
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
        return AgentCredential::agentToken(static::getContainer(), $owner);
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
