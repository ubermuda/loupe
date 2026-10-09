<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Uid\Uuid;

final class ShowBoardControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_board_shows_three_columns_with_one_drop_target_each_and_no_backlog(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-columns@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Backlog card', 'backlog');
        $this->card($em, $project, 'Next card', 'next');
        $backlogId = (string) $this->column($project, 'backlog')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('.lp-board__column'));
        self::assertCount(0, $crawler->filter('.lp-board__column[data-column-id="'.$backlogId.'"]'));
        self::assertCount(3, $crawler->filter('.lp-board__column [data-board-drag-target="group"]'));
        self::assertCount(0, $crawler->filter('[data-priority], [data-card-priority]'));
        self::assertCount(1, $crawler->filter('[data-board-drag-target="card"]'));
        self::assertCount(1, $crawler->filter('dialog[data-card-drawer-target="dialog"] turbo-frame#card-drawer-frame'));
        self::assertCount(1, $crawler->filter('.lp-board-card a[data-turbo-frame="card-drawer-frame"][data-action="click->card-drawer#prepare"]'));
        self::assertCount(3, $crawler->filter('a.lp-board__add-card[data-turbo-frame="card-drawer-frame"][data-action="click->card-drawer#prepare"]'));
        self::assertCount(1, $crawler->filter('.lp-board-toolbar a[href$="/board/cards/new"][data-turbo-frame="card-drawer-frame"]'));
        self::assertSelectorTextContains('.lp-board-toolbar h1', 'Project board');
        self::assertSelectorNotExists('.lp-workspace-desc');
        self::assertSelectorTextContains('.lp-board-toolbar__count', '1 card');
        self::assertSelectorTextContains('.lp-board-toolbar__mode-button[aria-pressed="true"]', 'Board');
        self::assertSelectorExists('a[href="/projects/'.$project->id.'/edit"]');

        $counts = $crawler->filter('.lp-board__column-count')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        self::assertSame(['1', '0', '0'], $counts);
    }

    public function test_the_backlog_button_links_to_the_backlog_shows_its_count_and_takes_a_drop(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-backlog-button@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Waiting', 'backlog');
        $this->card($em, $project, 'Waiting too', 'backlog', 1);
        $this->card($em, $project, 'Next card', 'next');
        $backlogId = (string) $this->column($project, 'backlog')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $button = $crawler->filter('#board a.lp-board-backlog');
        self::assertCount(1, $button);
        self::assertSame('/projects/'.$project->id.'/board/backlog', $button->attr('href'));
        self::assertSame('2', trim($button->filter('#board-count-'.$backlogId)->text()));
        // The toolbar survives a frame reload as it is, so the count must sit outside it.
        self::assertCount(0, $crawler->filter('[data-turbo-permanent] .lp-board-backlog'));
        self::assertSame('group', $button->attr('data-board-drag-target'));
        self::assertSame($backlogId, $button->attr('data-column'));
        self::assertSame('0', $button->attr('data-rankable'));
        self::assertNull($button->attr('data-lane'));
    }

    public function test_the_board_renders_the_columns_its_project_holds(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-own-columns@example.com');
        $project = $this->project($em, $owner);
        $this->column($project, 'next')->label = 'Ideas';
        $em->persist(new BoardColumn(project: $project, label: 'Won’t do', slug: 'wont-do', position: 4, terminal: true));
        $em->flush();
        $this->card($em, $project, 'Dropped', 'wont-do');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $titles = $crawler->filter('.lp-board__column-title')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        // A literal label passes through the translator unchanged.
        self::assertSame(['Ideas', 'In progress', 'Done', 'Won’t do'], $titles);
        $wontDo = $crawler->filter('.lp-board__column')->last();
        self::assertStringContainsString('Dropped', $wontDo->text());
        self::assertCount(1, $wontDo->filter('.lp-board__column-link'));
    }

    public function test_a_card_face_carries_the_move_fields_and_no_move_control(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-face@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Draggable', 'next');
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        // Dragging is the only interaction the face offers, so nothing renders a
        // control. The drop submits the board's one hidden move form.
        $face = $crawler->filter('[data-card-id="'.$cardId.'"]');
        self::assertStringContainsString('pointerdown->board-drag#press', (string) $face->attr('data-action'));
        // Hovering the whole card hands the prefetch to the title link.
        self::assertStringContainsString('mouseenter->card-prefetch#enter', (string) $face->attr('data-action'));
        self::assertCount(1, $face->filter('a.lp-board-card__title[data-card-prefetch-target="link"]'));
        self::assertCount(1, $crawler->filter('#board [data-board-drag-target="message"]'));
        self::assertCount(0, $face->filter('form'));
        self::assertCount(1, $crawler->filter('#board > form[hidden][data-board-drag-target="moveForm"] select[name$="[column]"]'));
        self::assertCount(0, $face->filter('details'));
        self::assertCount(0, $face->filter('button'));
    }

    public function test_a_card_shows_its_type_and_a_pull_request_indicator(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-indicator@example.com');
        $project = $this->project($em, $owner);
        $plain = $this->card($em, $project, 'No links', 'next');
        $linked = $this->card($em, $project, 'Has links', 'next');
        $linked->replacePullRequests(new CardPullRequest(
            card: $linked,
            url: 'https://github.com/loupe/loupe/pull/7',
            forge: Forge::GitHub,
            repository: 'loupe/loupe',
            number: 7,
        ));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-card-id="'.$linked->id.'"] .lp-board-card__pulls'));
        self::assertCount(0, $crawler->filter('[data-card-id="'.$plain->id.'"] .lp-board-card__pulls'));
        self::assertStringContainsString('Feature', $crawler->filter('[data-card-id="'.$plain->id.'"]')->text());

        $list = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/list');
        self::assertSame(['Work', 'Type', 'Status', 'Parent', 'Agent', 'Feedback'], $list->filter('.lp-board-list__header span')->each(static fn ($cell): string => $cell->text()));
        $row = $list->filter('.lp-board-list__row[data-card-title="No links"] > span');
        self::assertCount(6, $row);
        self::assertSame('Feature', $row->eq(1)->text());

        self::assertSame('lime', $crawler->filter('[data-card-id="'.$plain->id.'"] .lp-tag')->attr('data-tone'));
        self::assertSame('lime', $row->eq(1)->filter('.lp-tag')->attr('data-tone'));
        $column = $row->eq(2)->filter('.lp-tag');
        self::assertCount(1, $column);
        self::assertContains($column->attr('data-tone'), ['neutral', 'lime', 'purple', 'amber', 'green']);
        self::assertCount(1, $crawler->filter('.lp-board__column-head .lp-tone-dot--green'));
    }

    public function test_the_done_column_shows_only_the_recent_slice_and_links_to_the_history(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-done@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Finished today', 'done');
        $old = $this->card($em, $project, 'Finished long ago', 'done');
        $old->completedAt = new \DateTimeImmutable('-30 days');
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $done = $crawler->filter('.lp-board__column')->last();
        self::assertStringContainsString('Finished today', $done->text());
        self::assertStringNotContainsString('Finished long ago', $done->text());
        // The link still counts every Done card, not only the ones on screen.
        self::assertStringContainsString('2', $done->filter('.lp-board__column-link')->text());
        self::assertStringContainsString(
            '/board/terminal/',
            (string) $done->filter('.lp-board__column-link')->attr('href'),
        );
    }

    public function test_a_stranger_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-owner@example.com');
        $stranger = $this->user($em, 'board-stranger@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_sidebar_offers_the_board(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-sidebar@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href$="/board"]'));
    }

    public function test_the_board_loads_every_card_pull_request_with_the_cards(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-queries@example.com');
        $project = $this->project($em, $owner, 'many-cards');
        for ($index = 0; $index < 12; ++$index) {
            $this->linkedCard($em, $project, 'Card '.$index);
        }
        $em->clear();

        $client->loginUser($owner);
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $boardReads = 0;
        $lazyLinkReads = 0;
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                $sql = (string) $query['sql'];
                if (!str_starts_with($sql, 'SELECT')) {
                    continue;
                }
                if (str_contains($sql, 'FROM board_cards')) {
                    ++$boardReads;
                }
                if (str_contains($sql, 'FROM board_card_pull_requests')) {
                    ++$lazyLinkReads;
                }
            }
        }

        // Guard: without it the link assertion below would also hold for a
        // request that read no cards at all.
        self::assertGreaterThan(0, $boardReads);
        // The links ride along on the board's own query. Drop the fetch-join in
        // CardRepository and each card on the page loads its own.
        self::assertSame(0, $lazyLinkReads);
    }

    public function test_the_board_reads_documents_in_a_fixed_number_of_queries_whatever_the_card_count(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'board-document-queries@example.com');
        $boards = [];
        foreach (['three-cards' => 3, 'twelve-cards' => 12] as $name => $size) {
            $project = $this->project($em, $owner, $name);
            for ($index = 0; $index < $size; ++$index) {
                $this->documentedCard($em, $project, 'Card '.$index, 1 + $index % 2);
            }
            $boards[$name] = '/projects/'.$project->id.'/board';
        }
        $em->clear();

        $client->loginUser($owner);
        // The first request shares the kernel the fixtures used, so its profile holds their queries too.
        $client->request(Request::METHOD_GET, $boards['three-cards']);

        $reads = [];
        foreach ($boards as $name => $url) {
            $client->enableProfiler();
            $client->request(Request::METHOD_GET, $url);
            self::assertResponseIsSuccessful();

            $profile = $client->getProfile();
            self::assertInstanceOf(Profile::class, $profile);
            $collector = $profile->getCollector('db');
            self::assertInstanceOf(DoctrineDataCollector::class, $collector);

            $selects = 0;
            $documentReads = 0;
            foreach ($collector->getQueries() as $queries) {
                foreach ($queries as $query) {
                    $sql = (string) $query['sql'];
                    if (!str_starts_with($sql, 'SELECT')) {
                        continue;
                    }
                    ++$selects;
                    if (str_contains($sql, 'FROM board_card_documents')) {
                        ++$documentReads;
                    }
                }
            }
            $reads[$name] = ['selects' => $selects, 'documents' => $documentReads];
        }

        // The count of linked documents, and the stage documents in review that make a card need you.
        self::assertSame(2, $reads['three-cards']['documents']);
        self::assertSame(2, $reads['twelve-cards']['documents']);
        self::assertSame($reads['three-cards']['selects'], $reads['twelve-cards']['selects']);
    }

    public function test_a_card_shows_how_many_documents_it_links(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-document-badge@example.com');
        $project = $this->project($em, $owner);
        $documented = $this->documentedCard($em, $project, 'Documented', 2);
        $plain = $this->card($em, $project, 'Plain', 'next');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertSame('2', trim($crawler->filter('#board-card-'.$documented->id.' .lp-board-card__documents')->text()));
        self::assertCount(0, $crawler->filter('#board-card-'.$plain->id.' .lp-board-card__documents'));
    }

    public function test_a_card_face_shows_the_title_and_not_the_body(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-title-only@example.com');
        $project = $this->project($em, $owner);
        $plain = $this->card($em, $project, 'Plain column card', 'next', body: 'Plain body marker text');
        $epic = $this->typed($em, $this->card($em, $project, 'Lane epic', 'next'), 'epic');
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'Lane child card', 'in-progress', body: 'Lane body marker text'));
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-board-lane[data-lane="'.$epic->id.'"] #board-card-'.$child->id));
        self::assertStringContainsString('Plain column card', $crawler->filter('#board-card-'.$plain->id)->text());
        self::assertStringContainsString('Lane child card', $crawler->filter('#board-card-'.$child->id)->text());
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Plain body marker text', $content);
        self::assertStringNotContainsString('Lane body marker text', $content);
    }

    public function test_the_board_and_the_placement_agree_on_the_digest_of_a_card_with_documents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-document-digest@example.com');
        $project = $this->project($em, $owner);
        $card = $this->documentedCard($em, $project, 'Documented', 2);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertResponseIsSuccessful();
        $boardDigest = $crawler->filter('#board-card-'.$card->id)->attr('data-card-digest');
        self::assertNotEmpty($boardDigest);
        self::assertSame($boardDigest, $this->listOf($client, $project)->filter('#board-row-'.$card->id)->attr('data-card-digest'));

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id.'/placement');
        self::assertResponseIsSuccessful();
        $placement = new Crawler((string) $client->getResponse()->getContent());
        self::assertSame($boardDigest, $placement->filter('#board-card-'.$card->id)->attr('data-card-digest'));
        self::assertSame($boardDigest, $placement->filter('#board-row-'.$card->id)->attr('data-card-digest'));
    }

    public function test_the_card_and_its_row_agree_on_the_digest_of_an_epic_with_a_warning(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-epic-digest@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Epic', 'in-progress'), 'epic');
        $epic->laneEnabled = false;
        $this->childOf($em, $epic, $this->card($em, $project, 'Child', 'next'));
        $this->workerRun($em, $project, $epic, WorkerRunState::GaveUp, 'Gave up.');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#board-card-'.$epic->id.' [data-card-progress]'));
        self::assertCount(0, $crawler->filter('#board-card-'.$epic->id.' [data-card-run-warning]'));
        self::assertCount(1, $crawler->filter('#board-card-'.$epic->id.' .lp-state-mark--stuck'));
        $boardDigest = $crawler->filter('#board-card-'.$epic->id)->attr('data-card-digest');
        self::assertSame($boardDigest, $this->listOf($client, $project)->filter('#board-row-'.$epic->id)->attr('data-card-digest'));

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$epic->id.'/placement');
        self::assertResponseIsSuccessful();
        $placement = new Crawler((string) $client->getResponse()->getContent());
        self::assertSame($boardDigest, $placement->filter('#board-card-'.$epic->id)->attr('data-card-digest'));
        self::assertSame($boardDigest, $placement->filter('#board-row-'.$epic->id)->attr('data-card-digest'));
    }

    public function test_a_card_shows_the_badges_of_its_read_pull_request_on_its_row_and_one_mark_on_its_face(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-badges@example.com');
        $project = $this->project($em, $owner);
        $failing = $this->card($em, $project, 'Failing', 'in-progress');
        $this->linkReadPullRequest($em, $failing, new PullRequestSnapshot(checks: PullRequestChecks::Failed, mergeability: PullRequestMergeability::Conflicting));
        $unread = $this->card($em, $project, 'Unread', 'in-progress');
        $this->linkReadPullRequest($em, $unread, new PullRequestSnapshot(checks: PullRequestChecks::Failed), read: false);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#board-card-'.$failing->id.' [data-card-badges]'));
        self::assertCount(0, $crawler->filter('#board-card-'.$failing->id.' .lp-status-chip'));
        self::assertCount(1, $crawler->filter('#board-card-'.$failing->id.' .lp-state-mark--stuck'));
        $list = $this->listOf($client, $project);
        self::assertSame('checks-failed conflict', $list->filter('#board-row-'.$failing->id.' [data-card-badges]')->attr('data-card-badges'));
        self::assertCount(0, $crawler->filter('#board-card-'.$unread->id.' [data-card-badges]'));
        $boardDigest = $crawler->filter('#board-card-'.$failing->id)->attr('data-card-digest');
        self::assertSame($boardDigest, $list->filter('#board-row-'.$failing->id)->attr('data-card-digest'));

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$failing->id.'/placement');
        self::assertResponseIsSuccessful();
        $placement = new Crawler((string) $client->getResponse()->getContent());
        self::assertCount(0, $placement->filter('#board-card-'.$failing->id.' [data-card-badges]'));
        self::assertCount(1, $placement->filter('#board-card-'.$failing->id.' .lp-state-mark--stuck'));
        self::assertSame($boardDigest, $placement->filter('#board-card-'.$failing->id)->attr('data-card-digest'));
        self::assertSame($boardDigest, $placement->filter('#board-row-'.$failing->id)->attr('data-card-digest'));
    }

    public function test_a_card_shows_an_unmanaged_chip_on_its_face_and_a_paused_mark_in_place_of_a_chip(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-markers@example.com');
        $project = $this->project($em, $owner);
        $paused = $this->card($em, $project, 'Paused', 'in-progress');
        $this->pauseCard($paused);
        $held = $this->card($em, $project, 'Held', 'in-progress');
        $this->holdCard($project, $held);
        $plain = $this->card($em, $project, 'Plain', 'in-progress');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#board-card-'.$paused->id.' [data-card-badges]'));
        self::assertCount(1, $crawler->filter('#board-card-'.$paused->id.' .lp-state-mark--stuck'));
        self::assertSame('paused', $this->listOf($client, $project)->filter('#board-row-'.$paused->id.' [data-card-badges]')->attr('data-card-badges'));
        self::assertSame('unmanaged', $crawler->filter('#board-card-'.$held->id.' [data-card-badges]')->attr('data-card-badges'));
        self::assertSame('Unmanaged', trim($crawler->filter('#board-card-'.$held->id.' .lp-status-chip--neutral')->text()));
        self::assertSame('unmanaged', $this->listOf($client, $project)->filter('#board-row-'.$held->id.' [data-card-badges]')->attr('data-card-badges'));
        self::assertCount(0, $crawler->filter('#board-card-'.$plain->id.' [data-card-badges]'));

        foreach ([$held] as $card) {
            $boardDigest = $crawler->filter('#board-card-'.$card->id)->attr('data-card-digest');
            $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id.'/placement');
            self::assertResponseIsSuccessful();
            $placement = new Crawler((string) $client->getResponse()->getContent());
            self::assertSame(
                $crawler->filter('#board-card-'.$card->id.' [data-card-badges]')->attr('data-card-badges'),
                $placement->filter('#board-card-'.$card->id.' [data-card-badges]')->attr('data-card-badges'),
            );
            self::assertSame($boardDigest, $placement->filter('#board-card-'.$card->id)->attr('data-card-digest'));
        }
    }

    public function test_a_marker_changes_the_digest_of_a_card(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-marker-digest@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Held later', 'in-progress');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $before = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/board')->filter('#board-card-'.$card->id)->attr('data-card-digest');
        $project = $em->find(Project::class, $projectId) ?? self::fail('The project is stored.');
        $this->holdCard($project, $card);
        $em->clear();
        $after = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/board')->filter('#board-card-'.$card->id)->attr('data-card-digest');

        self::assertNotSame($before, $after);
    }

    public function test_the_board_reads_the_markers_in_one_query_each_whatever_the_card_count(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'board-marker-queries@example.com');
        $boards = [];
        foreach (['three-cards' => 3, 'twelve-cards' => 12] as $name => $size) {
            $project = $this->project($em, $owner, $name);
            for ($index = 0; $index < $size; ++$index) {
                $card = $this->card($em, $project, 'Card '.$index, 'next');
                match ($index % 3) {
                    0 => $this->pauseCard($card),
                    1 => $this->holdCard($project, $card),
                    default => null,
                };
            }
            $boards[$name] = '/projects/'.$project->id.'/board';
        }
        $em->clear();

        $client->loginUser($owner);
        // The first request shares the kernel the fixtures used, so its profile holds their queries too.
        $client->request(Request::METHOD_GET, $boards['three-cards']);

        $reads = [];
        foreach ($boards as $name => $url) {
            $client->enableProfiler();
            $crawler = $client->request(Request::METHOD_GET, $url);
            self::assertResponseIsSuccessful();
            self::assertCount(intdiv(\count($crawler->filter('.lp-board-card')) + 2, 3), $crawler->filter('.lp-board-card .lp-state-mark--stuck'));

            $profile = $client->getProfile();
            self::assertInstanceOf(Profile::class, $profile);
            $collector = $profile->getCollector('db');
            self::assertInstanceOf(DoctrineDataCollector::class, $collector);

            $selects = 0;
            $pauseReads = 0;
            $holdReads = 0;
            foreach ($collector->getQueries() as $queries) {
                foreach ($queries as $query) {
                    $sql = (string) $query['sql'];
                    if (!str_starts_with($sql, 'SELECT')) {
                        continue;
                    }
                    ++$selects;
                    $pauseReads += str_contains($sql, 'FROM card_pauses') ? 1 : 0;
                    $holdReads += str_contains($sql, 'FROM bridge_card_holds') ? 1 : 0;
                }
            }
            $reads[$name] = ['selects' => $selects, 'pauses' => $pauseReads, 'holds' => $holdReads];
        }

        self::assertSame(['selects' => $reads['three-cards']['selects'], 'pauses' => 1, 'holds' => 1], $reads['three-cards']);
        self::assertSame(['selects' => $reads['three-cards']['selects'], 'pauses' => 1, 'holds' => 1], $reads['twelve-cards']);
    }

    /** The mark holds wherever the card goes, until a newer run of the card clears it. The tile draws no run warning. */
    public function test_a_card_shows_a_stuck_mark_for_the_run_that_gave_up_in_any_column(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-run-warning@example.com');
        $project = $this->project($em, $owner, 'warned');
        $stays = $this->card($em, $project, 'Stays', 'in-progress');
        $moved = $this->card($em, $project, 'Moved', 'next');
        $unnamed = $this->card($em, $project, 'Unnamed', 'next');
        $quiet = $this->card($em, $project, 'Quiet', 'next');
        $this->workerRun($em, $project, $stays, WorkerRunState::GaveUp, 'Tests <em>still</em> fail.');
        $this->workerRun($em, $project, $moved, WorkerRunState::GaveUp, 'Moved away.');
        $this->workerRun($em, $project, $unnamed, WorkerRunState::Blocked, 'Needs a token.');
        $this->workerRun($em, $project, $quiet, WorkerRunState::Succeeded, 'Done.');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-card-run-warning], .lp-board-card__warning'));
        $mark = $crawler->filter('[data-card-id="'.$stays->id.'"] .lp-state-mark--stuck');
        self::assertCount(1, $mark);
        self::assertSame('Stuck', $mark->filter('[role="img"]')->attr('aria-label'));
        self::assertStringContainsString('The last worker run did not finish its work.', $mark->filter('.lp-tooltip')->text());
        self::assertCount(1, $crawler->filter('[data-card-id="'.$unnamed->id.'"] .lp-state-mark--stuck'));
        self::assertCount(1, $crawler->filter('[data-card-id="'.$moved->id.'"] .lp-state-mark--stuck'));
        self::assertCount(0, $crawler->filter('[data-card-id="'.$quiet->id.'"] .lp-state-mark'));

        $list = $this->listOf($client, $project);
        foreach ([$stays, $moved, $unnamed, $quiet] as $card) {
            self::assertSame(
                $crawler->filter('#board-card-'.$card->id)->attr('data-card-digest'),
                $list->filter('#board-row-'.$card->id)->attr('data-card-digest'),
            );
        }
    }

    /** A card inside an epic lane shows its mark too, and lanes render through their own templates. */
    public function test_a_card_in_an_epic_lane_shows_a_stuck_mark_for_the_run_that_gave_up(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-lane-warning@example.com');
        $project = $this->project($em, $owner, 'laned');
        $epic = $this->typed($em, $this->card($em, $project, 'Epic', 'next'), 'epic');
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'Child', 'in-progress'));
        $loose = $this->card($em, $project, 'Loose', 'in-progress');
        $this->workerRun($em, $project, $child, WorkerRunState::GaveUp, 'Child gave up.');
        $this->workerRun($em, $project, $loose, WorkerRunState::Blocked, 'Loose is blocked.');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-board-lane[data-lane="'.$epic->id.'"] [data-card-id="'.$child->id.'"]'));
        self::assertCount(1, $crawler->filter('[data-card-id="'.$child->id.'"] .lp-state-mark--stuck'));
        self::assertCount(1, $crawler->filter('.lp-board-lane[data-lane="other"] [data-card-id="'.$loose->id.'"] .lp-state-mark--stuck'));
    }

    /** A run that changes state reloads the board, so the page listens on the worker-run topic too. */
    public function test_the_board_subscribes_to_its_board_and_worker_run_topics(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-topics@example.com');
        $project = $this->project($em, $owner, 'topics');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        $subscribed = $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn (Crawler $input): ?string => $input->attr('value'));
        self::assertContains($topics->forBoard($projectId), $subscribed);
        self::assertContains($topics->forWorkerRuns($projectId), $subscribed);
    }

    /**
     * A resume is received after an event that waits behind it, and ends before that event runs. The run that
     * ended last decides the mark, so its later success clears it.
     */
    public function test_the_run_that_ended_last_decides_the_mark(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-run-warning-order@example.com');
        $project = $this->project($em, $owner, 'ordered');
        $cleared = $this->card($em, $project, 'Cleared', 'in-progress');
        $warned = $this->card($em, $project, 'Warned', 'in-progress');
        $this->workerRun($em, $project, $cleared, WorkerRunState::Succeeded, 'Done.', receivedAt: '-10 minutes', endedAt: '-1 minute', closedAt: '-1 minute');
        $this->workerRun($em, $project, $cleared, WorkerRunState::GaveUp, 'Gave up.', receivedAt: '-5 minutes', endedAt: '-3 minutes', closedAt: '-3 minutes');
        $this->workerRun($em, $project, $warned, WorkerRunState::GaveUp, 'Gave up.', receivedAt: '-10 minutes', endedAt: '-1 minute', closedAt: '-1 minute');
        $this->workerRun($em, $project, $warned, WorkerRunState::Succeeded, 'Done.', receivedAt: '-5 minutes', endedAt: '-3 minutes', closedAt: '-3 minutes');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-card-id="'.$warned->id.'"] .lp-state-mark--stuck'));
        self::assertCount(0, $crawler->filter('[data-card-id="'.$cleared->id.'"] .lp-state-mark'));
    }

    /** Two bridges with different clocks: the server order of the outcome reports decides, not the bridge end times. */
    public function test_the_run_the_server_closed_last_decides_the_mark_across_bridge_clocks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-run-warning-clocks@example.com');
        $project = $this->project($em, $owner, 'clocks');
        $cleared = $this->card($em, $project, 'Cleared', 'in-progress');
        $warned = $this->card($em, $project, 'Warned', 'in-progress');
        $this->workerRun($em, $project, $cleared, WorkerRunState::GaveUp, 'Gave up.', receivedAt: '-20 minutes', endedAt: '-1 minute', closedAt: '-10 minutes');
        $this->workerRun($em, $project, $cleared, WorkerRunState::Succeeded, 'Done.', receivedAt: '-15 minutes', endedAt: '-30 minutes', closedAt: '-2 minutes');
        $this->workerRun($em, $project, $warned, WorkerRunState::Succeeded, 'Done.', receivedAt: '-20 minutes', endedAt: '-1 minute', closedAt: '-10 minutes');
        $this->workerRun($em, $project, $warned, WorkerRunState::GaveUp, 'Gave up.', receivedAt: '-15 minutes', endedAt: '-30 minutes', closedAt: '-2 minutes');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-card-id="'.$warned->id.'"] .lp-state-mark--stuck'));
        self::assertCount(0, $crawler->filter('[data-card-id="'.$cleared->id.'"] .lp-state-mark'));
    }

    private function workerRun(EntityManagerInterface $em, Project $project, Card $card, WorkerRunState $state, string $output, string $receivedAt = 'now', string $endedAt = 'now', string $closedAt = 'now'): WorkerRun
    {
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: $card->number,
            workKind: 'implement',
            state: $state,
            runKey: Uuid::v7(),
            endedAt: new \DateTimeImmutable($endedAt),
            exitCode: 0,
            hasResult: true,
            output: $output,
            receivedAt: new \DateTimeImmutable($receivedAt),
        );
        $em->persist($run);
        $em->persist(new WorkerRunStateChange($run, $state, new \DateTimeImmutable($endedAt), new \DateTimeImmutable($closedAt)));
        $em->flush();

        return $run;
    }

    private function linkedCard(EntityManagerInterface $em, Project $project, string $title): Card
    {
        $card = $this->card($em, $project, $title);
        $card->replacePullRequests(new CardPullRequest(
            card: $card,
            url: 'https://github.com/loupe/loupe/pull/1',
            forge: Forge::GitHub,
            repository: 'loupe/loupe',
            number: 1,
        ));
        $em->flush();

        return $card;
    }

    private function documentedCard(EntityManagerInterface $em, Project $project, string $title, int $documents): Card
    {
        $card = $this->card($em, $project, $title, 'next');
        for ($index = 0; $index < $documents; ++$index) {
            $document = new Document($project->owner, $project, $title.' design '.$index);
            $document->addVersion('# Design', '<h1>Design</h1>');
            $em->persist($document);
            $em->persist(new CardDocument($card, $document));
        }
        $em->flush();

        return $card;
    }

    private function pauseCard(Card $card): void
    {
        $pause = static::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $pause);
        $pause(new PauseCardCommand($card, 'no-bridge-took-work', 'implement', CardPauseKind::WorkTimeout));
    }

    private function holdCard(Project $project, Card $card): void
    {
        $holds = static::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);
        $holds->hold($project, $card->id ?? throw new \LogicException('A flushed card has an id.'), null);
    }

    private function listOf(KernelBrowser $client, Project $project): Crawler
    {
        $list = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/list');
        self::assertResponseIsSuccessful();

        return $list;
    }
}
