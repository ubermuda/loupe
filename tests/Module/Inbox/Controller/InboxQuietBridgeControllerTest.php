<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Uid\Uuid;

/** The heartbeat interval stays at its 60-second default, so a bridge goes quiet after 180 seconds. */
final class InboxQuietBridgeControllerTest extends WebTestCase
{
    use InboxScenario;

    private const string NOW = '2026-09-14 16:00:00';
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
        self::assertCount(1, $this->block($page, $closed));
        self::assertCount(0, $this->block($page, $closed)->filter('[data-inbox-bridge]'));
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
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        $bridgeReads = 0;
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                if (str_contains((string) $query['sql'], 'FROM bridges')) {
                    ++$bridgeReads;
                }
            }
        }
        self::assertSame(1, $bridgeReads);
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

    private function page(): Crawler
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox');
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
