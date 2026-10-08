<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Form\SetCardLaneFormType;
use App\Module\Board\Service\LaneDecks;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\UX\Turbo\TurboBundle;

/** The board page with epic lanes, and without them. */
final class BoardLanesTest extends WebTestCase
{
    use BoardScenario;

    public function test_a_board_with_no_epic_draws_no_lane_markup(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'lanes-none@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $this->card($em, $project, 'Plain', 'triage');
        $this->card($em, $project, 'Finished', 'done');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $this->assertNoLanes($crawler);
        self::assertCount(4, $crawler->filter('.lp-board__column [data-board-drag-target="group"]'));
        self::assertCount(0, $crawler->filter('[data-card-parent-tag], [data-card-progress]'));
    }

    public function test_an_epic_lane_holds_its_children_and_the_other_row_holds_the_rest(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'lanes-draw@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Board epic', 'next'), 'epic');
        $open = $this->childOf($em, $epic, $this->card($em, $project, 'Open child', 'triage'));
        $done = $this->childOf($em, $epic, $this->card($em, $project, 'Done child', 'done'));
        $loose = $this->card($em, $project, 'Loose card', 'triage');
        [$epicId, $epicNumber, $openId, $doneId, $looseId] = [(string) $epic->id, $epic->number, (string) $open->id, (string) $done->id, (string) $loose->id];
        $triageId = (string) $this->column($project, 'triage')->id;
        $doneColumnId = (string) $this->column($project, 'done')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $lane = $crawler->filter('.lp-board-lane[data-lane="'.$epicId.'"]');
        self::assertCount(1, $lane);
        self::assertSame('board-lane', $lane->attr('data-controller'));
        $head = $lane->filter('.lp-board-lane__head');
        self::assertSame('laneHead', $head->attr('data-board-filter-target'));
        self::assertSame('Board epic', $head->attr('data-card-title'));
        self::assertStringContainsString('#'.$epicNumber, $head->text());
        self::assertCount(1, $head->filter('a[href$="/board/cards/'.$epicId.'"]'));
        self::assertSame('1/2 done', trim($head->filter('[data-lane-progress]')->text()));
        self::assertCount(1, $head->filter('button[data-action="board-lane#toggle"][aria-expanded="true"]'));
        self::assertCount(1, $head->filter('form[name="'.SetCardLaneFormType::PREFIX.$epicId.'"] input[name$="[returnTo]"][value="board"]'));

        // The epic is its lane header, not a card.
        self::assertCount(0, $crawler->filter('[data-board-drag-target="card"][data-card-id="'.$epicId.'"]'));
        self::assertCount(1, $lane->filter('[data-board-drag-target="group"][data-lane="'.$epicId.'"][data-column="'.$triageId.'"] [data-card-id="'.$openId.'"]'));
        self::assertCount(1, $lane->filter('[data-board-drag-target="group"][data-lane="'.$epicId.'"][data-column="'.$doneColumnId.'"] [data-card-id="'.$doneId.'"]'));
        self::assertCount(4, $lane->filter('[data-board-drag-target="group"]'));
        // A child inside its own lane needs no parent tag.
        self::assertCount(0, $lane->filter('[data-card-parent-tag]'));

        $other = $crawler->filter('.lp-board-lane[data-lane="other"]');
        self::assertCount(1, $other);
        self::assertStringContainsString('Other cards', $other->filter('.lp-board-lane__head')->text());
        self::assertCount(1, $other->filter('[data-lane="other"][data-column="'.$triageId.'"] [data-card-id="'.$looseId.'"]'));
        self::assertSame('other', $crawler->filter('.lp-board-lane')->last()->attr('data-lane'));

        // A lane cell of an open column is ranked, and a terminal one is not.
        self::assertCount(3, $lane->filter('[data-board-drag-target="group"][data-rankable="1"]'));
        self::assertCount(1, $lane->filter('[data-column="'.$doneColumnId.'"][data-rankable="0"]'));
        $counts = $crawler->filter('.lp-board__column-count')->each(static fn (Crawler $node): string => trim($node->text()));
        // Each lane repeats the column heads and counts its own cards, with no separate head row.
        self::assertSame(['1', '0', '0', '1', '1', '0', '0', '0'], $counts);
        self::assertCount(4, $lane->filter('.lp-board-lane__column .lp-board__column-head'));
        // Every lane shows the same column head: colour, label and count.
        self::assertCount(8, $crawler->filter('.lp-board__column-head'));
        self::assertCount(0, $crawler->filter('.lp-board__column-head button, .lp-board__column-head [draggable]'));
        self::assertCount(4, $other->filter('.lp-board-lane__column[data-column-id]'));
        self::assertStringContainsString('lp-board-lane--epic', (string) $lane->attr('class'));
        self::assertStringContainsString('lp-board-lane--other', (string) $other->attr('class'));
        self::assertCount(0, $lane->filter('.lp-board__add-card'));
        self::assertCount(4, $other->filter('.lp-board__add-card'));
        self::assertSelectorTextContains('.lp-board-toolbar__count', '3 cards');
        // The list view still lists the epic.
        $list = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/list');
        self::assertCount(1, $list->filter('.lp-board-list__row[data-card-id="'.$epicId.'"]'));
    }

    public function test_a_lane_switched_off_mixes_its_children_in_with_a_parent_tag(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'lanes-off@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Quiet epic', 'in-progress'), 'epic');
        $done = [];
        for ($index = 0; $index < 3; ++$index) {
            $done[] = $this->childOf($em, $epic, $this->card($em, $project, 'Done '.$index, 'done'));
        }
        $open = [];
        for ($index = 0; $index < 4; ++$index) {
            $open[] = $this->childOf($em, $epic, $this->card($em, $project, 'Open '.$index, 'triage', $index));
        }
        [$epicId, $epicNumber, $openId] = [(string) $epic->id, $epic->number, (string) $open[0]->id];
        $em->clear();

        $client->loginUser($owner);
        $client->request(
            Request::METHOD_POST,
            '/projects/'.$project->id.'/board/cards/'.$epicId.'/lane',
            [SetCardLaneFormType::PREFIX.$epicId => ['laneEnabled' => '0', 'returnTo' => 'board', '_token' => 'csrf-token']],
            server: ['HTTP_REFERER' => 'http://localhost/projects/'.$project->id.'/board'],
        );
        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        $crawler = $client->followRedirect();

        self::assertResponseIsSuccessful();
        $this->assertNoLanes($crawler);
        $tag = $crawler->filter('[data-card-id="'.$openId.'"] [data-card-parent-tag]');
        self::assertCount(1, $tag);
        self::assertSame('#'.$epicNumber, trim($tag->text()));
        self::assertCount(7, $crawler->filter('[data-card-parent-tag]'));
        $epicCard = $crawler->filter('[data-board-drag-target="card"][data-card-id="'.$epicId.'"]');
        self::assertCount(1, $epicCard);
        self::assertSame('3/7 done', trim($epicCard->filter('[data-card-progress]')->text()));
    }

    public function test_the_lane_toggle_on_the_board_answers_with_an_empty_stream_and_saves_the_lane(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'lanes-stream@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Streamed epic', 'next'), 'epic');
        $this->childOf($em, $epic, $this->card($em, $project, 'Child'));
        [$projectId, $epicId] = [(string) $project->id, (string) $epic->id];
        $em->clear();

        $client->loginUser($owner);
        $this->postLaneAsStream($client, $projectId, $epicId, '0');

        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith(TurboBundle::STREAM_MEDIA_TYPE, (string) $client->getResponse()->headers->get('Content-Type'));
        $body = (string) $client->getResponse()->getContent();
        self::assertSame('', trim($body));
        self::assertStringNotContainsString('target="board"', $body);
        self::assertStringNotContainsString('data-card-id', $body);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/board');
        self::assertCount(0, $crawler->filter('.lp-board-lane[data-lane="'.$epicId.'"]'));

        $this->postLaneAsStream($client, $projectId, $epicId, '1');
        self::assertResponseStatusCodeSame(200);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/board');
        self::assertCount(1, $crawler->filter('.lp-board-lane[data-lane="'.$epicId.'"]'));
    }

    public function test_a_refused_lane_toggle_on_the_board_prepends_its_message_and_leaves_no_flash(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'lanes-refused@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        [$projectId, $cardId] = [(string) $project->id, (string) $this->card($em, $project, 'Plain task', 'next')->id];
        $em->clear();

        $client->loginUser($owner);
        $this->postLaneAsStream($client, $projectId, $cardId, '1');

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith(TurboBundle::STREAM_MEDIA_TYPE, (string) $client->getResponse()->headers->get('Content-Type'));
        $stream = new Crawler((string) $client->getResponse()->getContent());
        $prepend = $stream->filter('turbo-stream[action="prepend"][target="main-content"]');
        self::assertCount(1, $prepend);
        self::assertStringContainsString('Only an epic has a lane.', $prepend->html());
        self::assertStringContainsString('lp-flash--error', $prepend->html());

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/board');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.lp-flash'));
    }

    public function test_a_done_epic_shows_as_one_card_and_its_children_leave_the_board(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'lanes-done@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Finished epic', 'done'), 'epic');
        $children = [];
        for ($index = 0; $index < 3; ++$index) {
            $children[] = (string) $this->childOf($em, $epic, $this->card($em, $project, 'Finished child '.$index, 'done'))->id;
        }
        $epicId = (string) $epic->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $this->assertNoLanes($crawler);
        $epicCard = $crawler->filter('[data-board-drag-target="card"][data-card-id="'.$epicId.'"]');
        self::assertSame('3/3 done', trim($epicCard->filter('[data-card-progress]')->text()));
        foreach ($children as $childId) {
            self::assertCount(0, $crawler->filter('[data-card-id="'.$childId.'"]'));
        }
    }

    public function test_an_epic_lane_head_shows_its_backlog_children_as_an_up_next_deck(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'lanes-deck@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Deck epic', 'next'), 'epic');
        $waiting = [];
        for ($index = LaneDecks::DECK_SIZE + 1; $index >= 0; --$index) {
            $waiting[$index] = (string) $this->childOf($em, $epic, $this->card($em, $project, 'Waiting '.$index, 'backlog', $index))->id;
        }
        ksort($waiting);
        $bare = $this->typed($em, $this->card($em, $project, 'Epic with nothing waiting', 'next', 1), 'epic');
        $this->card($em, $project, 'Waiting with no epic', 'backlog', 20);
        [$epicId, $bareId] = [(string) $epic->id, (string) $bare->id];
        $backlogId = (string) $this->column($project, 'backlog')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $head = $crawler->filter('#board-lane-'.$epicId.' .lp-board-lane__head');
        self::assertSame('Up next '.(LaneDecks::DECK_SIZE + 2).' in Backlog', $head->filter('.lp-board-lane__deck-label')->text());
        $deck = $head->filter('.lp-deck');
        self::assertSame('group', $deck->attr('data-board-drag-target'));
        self::assertNotNull($deck->attr('data-board-bucket'));
        self::assertSame($backlogId, $deck->attr('data-column'));
        self::assertSame($epicId, $deck->attr('data-lane'));
        self::assertSame('0', $deck->attr('data-rankable'));
        self::assertSame(
            \array_slice($waiting, 0, LaneDecks::DECK_SIZE),
            $deck->filter('[data-board-drag-target="card"]')->each(static fn (Crawler $card): string => (string) $card->attr('data-card-id')),
        );
        self::assertCount(0, $deck->filter('form'));
        self::assertCount(0, $deck->filter('[id^="board-card-"], [data-card-digest]'));
        $more = $deck->filter('.lp-deck__more');
        self::assertNull($more->attr('hidden'));
        self::assertSame('+2 more', trim($more->text()));
        self::assertStringEndsWith('/board/backlog?epic='.$epicId, (string) $more->attr('href'));

        self::assertCount(0, $crawler->filter('#board-lane-'.$bareId.' .lp-deck'));
        self::assertCount(0, $crawler->filter('.lp-board-lane--other .lp-deck'));
    }

    public function test_lanes_cost_a_fixed_number_of_queries_whatever_the_number_of_epics(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'lanes-queries@example.com');
        $plain = $this->project($em, $owner, 'plain');
        $this->addTriageColumn($plain);
        $em->flush();
        $this->card($em, $plain, 'Plain', 'triage');
        $epics = $this->project($em, $owner, 'epics');
        $this->addTriageColumn($epics);
        $em->flush();
        for ($index = 0; $index < 3; ++$index) {
            $epic = $this->typed($em, $this->card($em, $epics, 'Epic '.$index, 'next', $index), 'epic');
            $this->childOf($em, $epic, $this->card($em, $epics, 'Child '.$index, 'triage'));
            $this->childOf($em, $epic, $this->card($em, $epics, 'Done child '.$index, 'done'));
            $this->childOf($em, $epic, $this->card($em, $epics, 'Waiting child '.$index, 'backlog', $index));
        }
        $em->clear();
        $client->loginUser($owner);

        $plainReads = $this->boardCardReads($client, (string) $plain->id);
        $epicReads = $this->boardCardReads($client, (string) $epics->id);

        self::assertNotSame([], $plainReads);
        // The Up next decks cost two reads, whatever the number of epics and of Backlog cards.
        self::assertCount(\count($plainReads) + 2, $epicReads);
        $progressReads = array_filter($epicReads, static fn (string $sql): bool => str_contains($sql, 'GROUP BY c.parent_card_id'));
        self::assertCount(1, $progressReads);
    }

    /** @return list<string> the SELECT statements the board page ran on the cards table */
    private function boardCardReads(KernelBrowser $client, string $projectId): array
    {
        $client->enableProfiler();
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/board');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('[data-board-drag-target="card"]')->count());

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $reads = [];
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                $sql = (string) $query['sql'];
                if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM board_cards')) {
                    $reads[] = $sql;
                }
            }
        }

        return $reads;
    }

    private function postLaneAsStream(KernelBrowser $client, string $projectId, string $epicId, string $laneEnabled): void
    {
        $client->request(
            Request::METHOD_POST,
            '/projects/'.$projectId.'/board/cards/'.$epicId.'/lane',
            [SetCardLaneFormType::PREFIX.$epicId => ['laneEnabled' => $laneEnabled, 'returnTo' => 'board', '_token' => 'csrf-token']],
            server: [
                'HTTP_REFERER' => 'http://localhost/projects/'.$projectId.'/board',
                'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE,
            ],
        );
    }

    private function assertNoLanes(Crawler $crawler): void
    {
        self::assertCount(0, $crawler->filter('[data-lane], .lp-board-lane, [data-controller~="board-lane"]'));
        self::assertStringNotContainsString('Other cards', $crawler->filter('#board')->text());
    }
}
