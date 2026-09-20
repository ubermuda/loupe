<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class ShowAccountInboxControllerTest extends WebTestCase
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

    public function test_the_page_and_its_sidebar_link_are_absent_while_the_inbox_is_off(): void
    {
        $owner = $this->signedUpUser($this->em, 'account-inbox-off');
        $this->setInboxFlag(false);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/account/inbox');
        self::assertResponseStatusCodeSame(404);

        $this->client->request(Request::METHOD_GET, '/account/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/account/inbox"]');
    }

    public function test_the_sidebar_links_the_page_while_the_inbox_is_on(): void
    {
        $owner = $this->signedUpUser($this->em, 'account-inbox-nav');
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/account/profile');

        self::assertSelectorExists('.lp-sidebar__nav--secondary a[href="/account/inbox"]');
    }

    public function test_an_owner_with_no_open_ask_sees_an_empty_state(): void
    {
        $owner = $this->signedUpUser($this->em, 'account-inbox-empty');
        $project = $this->inboxProject($this->em, $owner);
        $item = $this->answered($this->em, $this->question($this->em, $project, 1));
        $this->askHolding($this->em, $project, [$item], closedAt: new \DateTimeImmutable());
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/account/inbox');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-empty-state'));
        self::assertCount(0, $crawler->filter('[data-inbox-ask-id]'));
    }

    public function test_only_open_asks_of_the_owners_projects_appear(): void
    {
        $owner = $this->signedUpUser($this->em, 'account-inbox-owner');
        $mine = $this->inboxProject($this->em, $owner);
        $open = $this->askHolding($this->em, $mine, [$this->question($this->em, $mine, 1)]);
        $closed = $this->askHolding($this->em, $mine, [$this->answered($this->em, $this->question($this->em, $mine, 2))], closedAt: new \DateTimeImmutable());

        $stranger = $this->signedUpUser($this->em, 'account-inbox-stranger');
        $theirs = $this->inboxProject($this->em, $stranger);
        $foreign = $this->askHolding($this->em, $theirs, [$this->question($this->em, $theirs, 1, title: 'A stranger asks')]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/account/inbox');

        self::assertResponseIsSuccessful();
        self::assertSame([(string) $mine->id], $this->projectIds($crawler));
        self::assertCount(0, $crawler->filter('[data-account-inbox-project="'.$theirs->id.'"]'));
        self::assertStringNotContainsString('A stranger asks', $crawler->filter('main')->text());
    }

    public function test_the_projects_read_by_name_with_their_count_and_their_oldest_wait(): void
    {
        $owner = $this->signedUpUser($this->em, 'account-inbox-order');
        $zulu = $this->namedProject($owner, 'Zulu');
        $alpha = $this->namedProject($owner, 'alpha');
        $zuluAsk = $this->askHolding($this->em, $zulu, [$this->question($this->em, $zulu, 1)], createdAt: new \DateTimeImmutable('-3 hours'));
        $alphaNewer = $this->askHolding($this->em, $alpha, [$this->question($this->em, $alpha, 1)], createdAt: new \DateTimeImmutable('-1 hour'));
        $alphaOlder = $this->askHolding($this->em, $alpha, [$this->question($this->em, $alpha, 2), $this->todo($this->em, $alpha, 3)], createdAt: new \DateTimeImmutable('-2 hours'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/account/inbox');

        self::assertResponseIsSuccessful();
        self::assertSame([(string) $alpha->id, (string) $zulu->id], $this->projectIds($crawler));
        // A missing translation renders its key, and the gate does not fail on that.
        self::assertDoesNotMatchRegularExpression('/\\binbox\\.[a-z_]+\\.[a-z_.]+/', $crawler->filter('main')->text());
        self::assertSelectorTextSame('[data-account-inbox-project="'.$alpha->id.'"] [data-account-inbox-open-count]', '3 open items');
        // The oldest of the project's open asks and loose items, not the newest.
        self::assertStringContainsString('2h ago', $crawler->filter('[data-account-inbox-project="'.$alpha->id.'"] .lp-account-inbox-card__wait')->text());
        self::assertStringContainsString('3h ago', $crawler->filter('[data-account-inbox-project="'.$zulu->id.'"] .lp-account-inbox-card__wait')->text());
    }

    public function test_the_page_is_read_only(): void
    {
        $owner = $this->signedUpUser($this->em, 'account-inbox-readonly');
        $project = $this->inboxProject($this->em, $owner);
        $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1), $this->todo($this->em, $project, 2)]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/account/inbox');

        self::assertResponseIsSuccessful();
        // The page decides where to go and answers nothing itself.
        self::assertCount(1, $crawler->filter('[data-account-inbox-project]'));
        self::assertCount(0, $crawler->filter('[data-inbox-item]'));
        self::assertCount(0, $crawler->filter('main form'));
    }

    public function test_an_open_item_outside_any_open_ask_keeps_its_project_on_the_page(): void
    {
        $owner = $this->signedUpUser($this->em, 'account-inbox-loose');
        $project = $this->inboxProject($this->em, $owner);
        $done = $this->answered($this->em, $this->question($this->em, $project, 1), InboxItemState::Done);
        $leftOpen = $this->todo($this->em, $project, 2, 'Review pull request 482');
        $this->askHolding($this->em, $project, [$done, $leftOpen], closedAt: new \DateTimeImmutable('-1 hour'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/account/inbox');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.lp-empty-state'));
        $group = $crawler->filter('[data-account-inbox-project="'.$project->id.'"]');
        self::assertCount(1, $group);
        self::assertSame('1 open item', $group->filter('[data-account-inbox-open-count]')->text());
        self::assertStringNotContainsString('Review pull request 482', $group->text());

        $path = (string) $group->attr('href');
        self::assertSame('/projects/'.$project->id.'/inbox', $path);
        $page = $this->client->request(Request::METHOD_GET, $path);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-inbox-item="2"]'));
        self::assertCount(1, $page->filter('form[name="inbox_done_'.$leftOpen->id.'"]'));
    }

    public function test_the_page_reads_every_ask_item_and_project_in_one_query(): void
    {
        $owner = $this->signedUpUser($this->em, 'account-inbox-queries');
        for ($p = 0; $p < 3; ++$p) {
            $project = $this->inboxProject($this->em, $owner);
            for ($a = 0; $a < 3; ++$a) {
                $this->askHolding($this->em, $project, [$this->question($this->em, $project, 2 * $a + 1), $this->todo($this->em, $project, 2 * $a + 2)]);
            }
            $this->askHolding($this->em, $project, [$this->todo($this->em, $project, 7), $this->todo($this->em, $project, 8)], closedAt: new \DateTimeImmutable());
        }
        for ($p = 0; $p < 2; ++$p) {
            $project = $this->inboxProject($this->em, $owner);
            $this->askHolding($this->em, $project, [$this->todo($this->em, $project, 1)], closedAt: new \DateTimeImmutable());
        }
        $this->setInboxFlag(true);
        $this->em->clear();

        $this->client->loginUser($owner);
        $this->client->enableProfiler();
        $crawler = $this->client->request(Request::METHOD_GET, '/account/inbox');
        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('[data-account-inbox-project]'));

        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $askReads = 0;
        $lazyReads = 0;
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                $sql = (string) $query['sql'];
                if (!str_starts_with($sql, 'SELECT')) {
                    continue;
                }
                if (str_contains($sql, 'FROM inbox_asks')) {
                    ++$askReads;

                    continue;
                }
                if (preg_match('/FROM (inbox_ask_items|inbox_items|projects) \w+ WHERE \w+\.id = \?/', $sql)
                    || preg_match('/FROM inbox_ask_items \w+ WHERE \w+\.ask_id = \?/', $sql)) {
                    ++$lazyReads;
                }
            }
        }

        // Guard: the assertion below also holds for a request that read no asks at all.
        self::assertSame(1, $askReads);
        self::assertSame(0, $lazyReads);
    }

    private function namedProject(User $owner, string $name): Project
    {
        $project = new Project($owner, $name.'-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /** @return list<string> */
    private function projectIds(Crawler $crawler): array
    {
        return $crawler->filter('[data-account-inbox-project]')->each(static fn (Crawler $node): string => (string) $node->attr('data-account-inbox-project'));
    }
}
