<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Uid\Uuid;

final class ShowBoardControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_board_shows_four_columns_with_one_drop_target_each(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-columns@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Backlog card', 'backlog');
        $this->card($em, $project, 'Next card', 'next');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('.lp-board__column'));
        self::assertCount(4, $crawler->filter('[data-board-drag-target="group"]'));
        self::assertCount(0, $crawler->filter('[data-priority], [data-card-priority]'));
        self::assertCount(2, $crawler->filter('[data-board-drag-target="card"]'));
        self::assertCount(1, $crawler->filter('dialog[data-card-drawer-target="dialog"] turbo-frame#card-drawer-frame'));
        self::assertCount(2, $crawler->filter('.lp-board-card a[data-turbo-frame="card-drawer-frame"][data-action="click->card-drawer#prepare"]'));
        self::assertCount(4, $crawler->filter('a.lp-board__add-card[data-turbo-frame="card-drawer-frame"][data-action="click->card-drawer#prepare"]'));
        self::assertCount(1, $crawler->filter('.lp-board-head a[href$="/board/cards/new"][data-turbo-frame="card-drawer-frame"]'));
        self::assertSelectorTextContains('.lp-board-toolbar__count', '2 cards');
        self::assertSelectorTextContains('.lp-board-toolbar__mode-button[aria-pressed="true"]', 'Board');
        self::assertSelectorExists('a[href="/projects/'.$project->id.'/edit"]');

        $counts = $crawler->filter('.lp-board__column-count')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        self::assertSame(['1', '1', '0', '0'], $counts);
    }

    public function test_the_board_renders_the_columns_its_project_holds(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-own-columns@example.com');
        $project = $this->project($em, $owner);
        $this->column($project, 'backlog')->label = 'Ideas';
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
        self::assertSame(['Ideas', 'Next', 'In progress', 'Done', 'Won’t do'], $titles);
        $wontDo = $crawler->filter('.lp-board__column')->last();
        self::assertStringContainsString('Dropped', $wontDo->text());
        self::assertCount(1, $wontDo->filter('.lp-board__column-link'));
    }

    public function test_a_card_face_carries_the_move_fields_and_no_move_control(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-face@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Draggable');
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        // The drag submits this form, so the fields are on the face. Dragging is
        // the only interaction the face offers, so nothing renders a control.
        $face = $crawler->filter('[data-card-id="'.$cardId.'"]');
        self::assertStringContainsString('pointerdown->board-drag#press', (string) $face->attr('data-action'));
        // Hovering the whole card hands the prefetch to the title link.
        self::assertStringContainsString('mouseenter->card-prefetch#enter', (string) $face->attr('data-action'));
        self::assertCount(1, $face->filter('a.lp-board-card__title[data-card-prefetch-target="link"]'));
        self::assertCount(1, $crawler->filter('#board [data-board-drag-target="message"]'));
        self::assertCount(1, $face->filter('form[hidden][data-board-drag-target="moveForm"]'));
        self::assertCount(1, $face->filter('select[name$="[column]"]'));
        self::assertCount(0, $face->filter('details'));
        self::assertCount(0, $face->filter('button'));
    }

    public function test_a_card_shows_its_type_and_a_pull_request_indicator(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-indicator@example.com');
        $project = $this->project($em, $owner);
        $plain = $this->card($em, $project, 'No links');
        $linked = $this->card($em, $project, 'Has links');
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

        self::assertSame(['Work', 'Type', 'Status', 'Parent', 'Agent', 'Feedback'], $crawler->filter('.lp-board-list__header span')->each(static fn ($cell): string => $cell->text()));
        $row = $crawler->filter('.lp-board-list__row[data-card-title="No links"] > span');
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
        $this->enableBoard();

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

    public function test_the_board_is_not_found_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $this->disableBoard();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-flag-off@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_stranger_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-owner@example.com');
        $stranger = $this->user($em, 'board-stranger@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_sidebar_offers_the_board_only_while_the_flag_is_on(): void
    {
        $client = static::createClient();
        $this->disableBoard();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-sidebar@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/board"]'));

        $this->enableBoard();
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href$="/board"]'));
    }

    public function test_the_board_loads_every_card_pull_request_with_the_cards(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

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

    /** The warning holds while the card stays in the column that started the run, or when the run names none. */
    public function test_a_card_shows_the_run_that_gave_up_until_it_leaves_the_column(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-run-warning@example.com');
        $project = $this->project($em, $owner, 'warned');
        $stays = $this->card($em, $project, 'Stays', 'in-progress');
        $moved = $this->card($em, $project, 'Moved', 'next');
        $unnamed = $this->card($em, $project, 'Unnamed', 'backlog');
        $quiet = $this->card($em, $project, 'Quiet', 'backlog');
        $gaveUp = $this->workerRun($em, $project, $stays, WorkerRunState::GaveUp, 'in-progress', 'Tests <em>still</em> fail.');
        $this->workerRun($em, $project, $moved, WorkerRunState::GaveUp, 'in-progress', 'Moved away.');
        $blocked = $this->workerRun($em, $project, $unnamed, WorkerRunState::Blocked, null, 'Needs a token.');
        $this->workerRun($em, $project, $quiet, WorkerRunState::Succeeded, 'backlog', 'Done.');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $warning = $crawler->filter('[data-card-id="'.$stays->id.'"] [data-card-run-warning]');
        self::assertCount(1, $warning);
        self::assertSame((string) $gaveUp->id, $warning->attr('data-card-run-warning'));
        self::assertStringContainsString('search='.$gaveUp->id, (string) $warning->attr('href'));
        self::assertStringContainsString('Gave up', $warning->text());
        self::assertStringContainsString('Tests <em>still</em> fail.', $warning->text());
        self::assertStringContainsString('Tests &lt;em&gt;still&lt;/em&gt; fail.', (string) $client->getResponse()->getContent());

        self::assertSame((string) $blocked->id, $crawler->filter('[data-card-id="'.$unnamed->id.'"] [data-card-run-warning]')->attr('data-card-run-warning'));
        self::assertCount(0, $crawler->filter('[data-card-id="'.$moved->id.'"] [data-card-run-warning]'));
        self::assertCount(0, $crawler->filter('[data-card-id="'.$quiet->id.'"] [data-card-run-warning]'));
    }

    /** A card inside an epic lane shows its warning too, and lanes render through their own templates. */
    public function test_a_card_in_an_epic_lane_shows_the_run_that_gave_up(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-lane-warning@example.com');
        $project = $this->project($em, $owner, 'laned');
        $epic = $this->typed($em, $this->card($em, $project, 'Epic', 'next'), CardType::Epic);
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'Child', 'backlog'));
        $loose = $this->card($em, $project, 'Loose', 'backlog');
        $childRun = $this->workerRun($em, $project, $child, WorkerRunState::GaveUp, 'backlog', 'Child gave up.');
        $looseRun = $this->workerRun($em, $project, $loose, WorkerRunState::Blocked, 'backlog', 'Loose is blocked.');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-board-lane[data-lane="'.$epic->id.'"] [data-card-id="'.$child->id.'"]'));
        self::assertSame((string) $childRun->id, $crawler->filter('[data-card-id="'.$child->id.'"] [data-card-run-warning]')->attr('data-card-run-warning'));
        self::assertSame((string) $looseRun->id, $crawler->filter('.lp-board-lane[data-lane="other"] [data-card-id="'.$loose->id.'"] [data-card-run-warning]')->attr('data-card-run-warning'));
    }

    /** A run that changes state reloads the board, so the page listens on the worker-run topic too. */
    public function test_the_board_subscribes_to_its_board_and_worker_run_topics(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

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
     * ended last decides the warning, so its later success clears it.
     */
    public function test_the_run_that_ended_last_decides_the_warning(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-run-warning-order@example.com');
        $project = $this->project($em, $owner, 'ordered');
        $cleared = $this->card($em, $project, 'Cleared', 'in-progress');
        $warned = $this->card($em, $project, 'Warned', 'in-progress');
        $this->workerRun($em, $project, $cleared, WorkerRunState::Succeeded, 'in-progress', 'Done.', receivedAt: '-10 minutes', endedAt: '-1 minute', closedAt: '-1 minute');
        $this->workerRun($em, $project, $cleared, WorkerRunState::GaveUp, 'in-progress', 'Gave up.', receivedAt: '-5 minutes', endedAt: '-3 minutes', closedAt: '-3 minutes');
        $this->workerRun($em, $project, $warned, WorkerRunState::GaveUp, 'in-progress', 'Gave up.', receivedAt: '-10 minutes', endedAt: '-1 minute', closedAt: '-1 minute');
        $this->workerRun($em, $project, $warned, WorkerRunState::Succeeded, 'in-progress', 'Done.', receivedAt: '-5 minutes', endedAt: '-3 minutes', closedAt: '-3 minutes');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-card-id="'.$warned->id.'"] [data-card-run-warning]'));
        self::assertCount(0, $crawler->filter('[data-card-id="'.$cleared->id.'"] [data-card-run-warning]'));
    }

    /** Two bridges with different clocks: the server order of the outcome reports decides, not the bridge end times. */
    public function test_the_run_the_server_closed_last_decides_the_warning_across_bridge_clocks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-run-warning-clocks@example.com');
        $project = $this->project($em, $owner, 'clocks');
        $cleared = $this->card($em, $project, 'Cleared', 'in-progress');
        $warned = $this->card($em, $project, 'Warned', 'in-progress');
        $this->workerRun($em, $project, $cleared, WorkerRunState::GaveUp, 'in-progress', 'Gave up.', receivedAt: '-20 minutes', endedAt: '-1 minute', closedAt: '-10 minutes');
        $this->workerRun($em, $project, $cleared, WorkerRunState::Succeeded, 'in-progress', 'Done.', receivedAt: '-15 minutes', endedAt: '-30 minutes', closedAt: '-2 minutes');
        $this->workerRun($em, $project, $warned, WorkerRunState::Succeeded, 'in-progress', 'Done.', receivedAt: '-20 minutes', endedAt: '-1 minute', closedAt: '-10 minutes');
        $this->workerRun($em, $project, $warned, WorkerRunState::GaveUp, 'in-progress', 'Gave up.', receivedAt: '-15 minutes', endedAt: '-30 minutes', closedAt: '-2 minutes');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-card-id="'.$warned->id.'"] [data-card-run-warning]'));
        self::assertCount(0, $crawler->filter('[data-card-id="'.$cleared->id.'"] [data-card-run-warning]'));
    }

    private function workerRun(EntityManagerInterface $em, Project $project, Card $card, WorkerRunState $state, ?string $column, string $output, string $receivedAt = 'now', string $endedAt = 'now', string $closedAt = 'now'): WorkerRun
    {
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            cardId: $card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: $card->number,
            ruleName: 'implement',
            state: $state,
            runKey: Uuid::v7(),
            endedAt: new \DateTimeImmutable($endedAt),
            exitCode: 0,
            hasResult: true,
            output: $output,
            receivedAt: new \DateTimeImmutable($receivedAt),
            cardColumn: $column,
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
}
