<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Service\HeartbeatInterval;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Support\AgentCredential;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/** Unless a test lowers it, the heartbeat interval stays at its 60-second default, so a bridge goes quiet after 180 seconds. */
final class InboxQuietBridgeControllerTest extends WebTestCase
{
    use InboxScenario;

    /** Far from the real clock, so a page that reads the real time instead of the injected clock cannot pass. */
    private const string NOW = '2031-03-02 09:00:00';
    private const int QUIET_AFTER_SECONDS = 180;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private int $nextNumber = 1;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::NOW));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = $this->signedUpUser($em, 'quiet-bridge');
        $this->project = $this->inboxProject($em, $this->owner);
        $this->setInboxFlag(true);
    }

    public function test_a_fresh_bridge_shows_when_it_was_last_heard_from_and_no_warning(): void
    {
        $ask = $this->openAsk($bridgeId = Uuid::v4());
        $this->bridgeSeenSecondsAgo($this->owner, $bridgeId, 60);

        $status = $this->bridgeStatusOf($this->page(), $ask);

        self::assertSame('heard', $status->attr('data-inbox-bridge'));
        self::assertStringContainsString('1m ago', $status->text());
        self::assertStringNotContainsString('No resume will come', $status->text());
    }

    public function test_a_bridge_heard_exactly_three_intervals_ago_is_not_yet_quiet(): void
    {
        $ask = $this->openAsk($bridgeId = Uuid::v4());
        $this->bridgeSeenSecondsAgo($this->owner, $bridgeId, self::QUIET_AFTER_SECONDS);

        $status = $this->bridgeStatusOf($this->page(), $ask);

        self::assertSame('heard', $status->attr('data-inbox-bridge'));
        self::assertStringNotContainsString('No resume will come', $status->text());
    }

    public function test_a_bridge_heard_more_than_three_intervals_ago_warns(): void
    {
        $ask = $this->openAsk($bridgeId = Uuid::v4());
        $this->bridgeSeenSecondsAgo($this->owner, $bridgeId, self::QUIET_AFTER_SECONDS + 1);

        $status = $this->bridgeStatusOf($this->page(), $ask);

        self::assertSame('quiet', $status->attr('data-inbox-bridge'));
        self::assertStringContainsString('3m ago', $status->text());
        self::assertStringContainsString('No resume will come while the bridge stays quiet.', $status->text());
    }

    /** A running bridge keeps the interval it read at its last reconnect, so a lowered flag must not make it look quiet. */
    public function test_a_lowered_interval_does_not_shorten_the_warning_below_the_default(): void
    {
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[HeartbeatInterval::FLAG]->value = 20;
        $this->em->flush();
        $ask = $this->openAsk($bridgeId = Uuid::v4());
        $this->bridgeSeenSecondsAgo($this->owner, $bridgeId, 100);

        $status = $this->bridgeStatusOf($this->page(), $ask);

        self::assertSame('heard', $status->attr('data-inbox-bridge'));
    }

    /** The heartbeat endpoint writes the row the page reads, keyed by the token's owner. */
    public function test_a_heartbeat_sent_through_the_endpoint_marks_the_ask_heard(): void
    {
        $ask = $this->openAsk($bridgeId = Uuid::v4());
        $raw = AgentCredential::agentToken(static::getContainer(), $this->owner);

        $this->client->request(
            Request::METHOD_PUT,
            '/api/bridges/'.$bridgeId->toRfc4122().'/heartbeat',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['projects' => [(string) $this->project->id], 'cliVersion' => 'b4e39aa7'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        $status = $this->bridgeStatusOf($this->page(), $ask);

        self::assertSame('heard', $status->attr('data-inbox-bridge'));
    }

    public function test_a_bridge_with_no_row_warns(): void
    {
        $ask = $this->openAsk(Uuid::v4());

        $status = $this->bridgeStatusOf($this->page(), $ask);

        self::assertSame('unheard', $status->attr('data-inbox-bridge'));
        self::assertStringContainsString('No resume will come while the bridge stays quiet.', $status->text());
    }

    /** The bridges key is the owner and the bridge id together, so a stranger's fresh row says nothing about this owner's bridge. */
    public function test_another_owners_row_with_the_same_bridge_id_does_not_count(): void
    {
        $ask = $this->openAsk($bridgeId = Uuid::v4());
        $this->bridgeSeenSecondsAgo($this->signedUpUser($this->em, 'quiet-bridge-stranger'), $bridgeId, 10);

        $status = $this->bridgeStatusOf($this->page(), $ask);

        self::assertSame('unheard', $status->attr('data-inbox-bridge'));
    }

    public function test_an_ask_from_an_interactive_session_shows_nothing(): void
    {
        $interactive = $this->openAsk(null);
        $bridged = $this->openAsk(Uuid::v4());

        $page = $this->page();

        self::assertCount(1, $this->block($page, $bridged)->filter('[data-inbox-bridge]'));
        self::assertCount(1, $this->block($page, $interactive));
        self::assertCount(0, $this->block($page, $interactive)->filter('[data-inbox-bridge]'));
    }

    public function test_a_closed_ask_shows_nothing(): void
    {
        $bridgeId = Uuid::v4();
        $this->bridgeSeenSecondsAgo($this->owner, $bridgeId, 3600);
        $open = $this->openAsk($bridgeId);
        $closed = $this->ask($bridgeId, new \DateTimeImmutable(self::NOW.' -1 minute'));

        $page = $this->page();

        self::assertSame('quiet', $this->bridgeStatusOf($page, $open)->attr('data-inbox-bridge'));
        // A closed ask keeps to the completed queue, and says nothing about its bridge.
        $completed = $this->page('?queue=completed');
        self::assertCount(1, $this->block($completed, $closed));
        self::assertCount(0, $this->block($completed, $closed)->filter('[data-inbox-bridge]'));
    }

    public function test_one_query_reads_every_bridge_on_the_page(): void
    {
        $first = Uuid::v4();
        $second = Uuid::v4();
        $this->bridgeSeenSecondsAgo($this->owner, $first, 30);
        $this->bridgeSeenSecondsAgo($this->owner, $second, 600);
        $this->openAsk($first);
        $this->openAsk($second);
        $this->openAsk($second);

        $this->client->enableProfiler();
        $page = $this->page();

        self::assertCount(3, $page->filter('[data-inbox-bridge]'));
        self::assertSame(1, $this->bridgeReads());
    }

    public function test_no_bridge_query_runs_when_no_open_ask_names_a_bridge(): void
    {
        $bridgeId = Uuid::v4();
        $this->bridgeSeenSecondsAgo($this->owner, $bridgeId, 30);
        $interactive = $this->openAsk(null);
        $this->ask($bridgeId, new \DateTimeImmutable(self::NOW.' -1 minute'));

        $this->client->enableProfiler();
        $page = $this->page();

        self::assertCount(1, $this->block($page, $interactive));
        self::assertSame(0, $this->bridgeReads());
    }

    /** Counts the page's reads of the bridges table, after checking the profiler saw queries at all. */
    private function bridgeReads(): int
    {
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        $all = 0;
        $bridgeReads = 0;
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                ++$all;
                if (str_contains((string) $query['sql'], 'FROM bridges')) {
                    ++$bridgeReads;
                }
            }
        }
        self::assertGreaterThan(0, $all);

        return $bridgeReads;
    }

    private function openAsk(?Uuid $bridgeId): InboxAsk
    {
        return $this->ask($bridgeId, null);
    }

    private function ask(?Uuid $bridgeId, ?\DateTimeImmutable $closedAt): InboxAsk
    {
        $ask = new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: $bridgeId, createdAt: new \DateTimeImmutable(self::NOW.' -1 hour'));
        $ask->closedAt = $closedAt;
        $ask->items->add(new InboxAskItem($ask, $this->question($this->em, $this->project, $this->nextNumber++)));
        $this->em->persist($ask);
        $this->em->flush();

        return $ask;
    }

    private function bridgeSeenSecondsAgo(User $owner, Uuid $bridgeId, int $seconds): void
    {
        $this->em->persist(new Bridge($owner, $bridgeId, [], 'b4e39aa7', new \DateTimeImmutable(self::NOW.' -'.$seconds.' seconds')));
        $this->em->flush();
    }

    private function page(string $query = ''): Crawler
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox'.$query);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function block(Crawler $page, InboxAsk $ask): Crawler
    {
        return $page->filter('[data-inbox-ask-id="'.$ask->id.'"]');
    }

    private function bridgeStatusOf(Crawler $page, InboxAsk $ask): Crawler
    {
        $status = $this->block($page, $ask)->filter('[data-inbox-bridge]');
        self::assertCount(1, $status);

        return $status;
    }
}
