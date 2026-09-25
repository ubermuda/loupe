<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\CardType;
use App\Module\Board\Form\SetCardLaneFormType;
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
        $this->enableBoard();
        $owner = $this->user($em, 'lanes-none@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Plain', 'backlog');
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
        $this->enableBoard();
        $owner = $this->user($em, 'lanes-draw@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Board epic', 'next'), CardType::Epic);
        $open = $this->childOf($em, $epic, $this->card($em, $project, 'Open child', 'backlog'));
        $done = $this->childOf($em, $epic, $this->card($em, $project, 'Done child', 'done'));
        $loose = $this->card($em, $project, 'Loose card', 'backlog');
        [$epicId, $epicNumber, $openId, $doneId, $looseId] = [(string) $epic->id, $epic->number, (string) $open->id, (string) $done->id, (string) $loose->id];
        $backlogId = (string) $this->column($project, 'backlog')->id;
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
        self::assertCount(1, $lane->filter('[data-board-drag-target="group"][data-lane="'.$epicId.'"][data-column="'.$backlogId.'"] [data-card-id="'.$openId.'"]'));
        self::assertCount(1, $lane->filter('[data-board-drag-target="group"][data-lane="'.$epicId.'"][data-column="'.$doneColumnId.'"] [data-card-id="'.$doneId.'"]'));
        self::assertCount(4, $lane->filter('[data-board-drag-target="group"]'));
        // A child inside its own lane needs no parent tag.
        self::assertCount(0, $lane->filter('[data-card-parent-tag]'));

        $other = $crawler->filter('.lp-board-lane[data-lane="other"]');
        self::assertCount(1, $other);
        self::assertStringContainsString('Other cards', $other->filter('.lp-board-lane__head')->text());
        self::assertCount(1, $other->filter('[data-lane="other"][data-column="'.$backlogId.'"] [data-card-id="'.$looseId.'"]'));
        self::assertSame('other', $crawler->filter('.lp-board-lane')->last()->attr('data-lane'));

        // A lane cell of an open column is ranked, and a terminal one is not.
        self::assertCount(3, $lane->filter('[data-board-drag-target="group"][data-rankable="1"]'));
        self::assertCount(1, $lane->filter('[data-column="'.$doneColumnId.'"][data-rankable="0"]'));
        $counts = $crawler->filter('.lp-board__column-count')->each(static fn (Crawler $node): string => trim($node->text()));
        // Each lane repeats the column heads and counts its own cards, with no separate head row.
        self::assertSame(['1', '0', '0', '1', '1', '0', '0', '0'], $counts);
        self::assertCount(4, $lane->filter('.lp-board-lane__column .lp-board__column-head'));
        self::assertCount(4, $lane->filter('[data-board-columns-target="column"] .lp-board__column-menu'));
        self::assertCount(0, $other->filter('[data-board-columns-target="column"], .lp-board__column-menu'));
        self::assertCount(4, $crawler->filter('[data-board-columns-target="column"]'));
        self::assertCount(0, $lane->filter('.lp-board__add-card'));
        self::assertCount(4, $other->filter('.lp-board__add-card'));
        self::assertSelectorTextContains('.lp-board-toolbar__count', '3 cards');
        // The list view still lists the epic.
        self::assertCount(1, $crawler->filter('.lp-board-list__row[data-list-card-id="'.$epicId.'"]'));
    }

    public function test_a_lane_switched_off_mixes_its_children_in_with_a_parent_tag(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'lanes-off@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Quiet epic', 'in-progress'), CardType::Epic);
        $done = [];
        for ($index = 0; $index < 3; ++$index) {
            $done[] = $this->childOf($em, $epic, $this->card($em, $project, 'Done '.$index, 'done'));
        }
        $open = [];
        for ($index = 0; $index < 4; ++$index) {
            $open[] = $this->childOf($em, $epic, $this->card($em, $project, 'Open '.$index, 'backlog', $index));
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

    public function test_the_lane_toggle_on_the_board_answers_with_the_board_stream(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'lanes-stream@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Streamed epic', 'next'), CardType::Epic);
        $this->childOf($em, $epic, $this->card($em, $project, 'Child'));
        $epicId = (string) $epic->id;
        $em->clear();

        $client->loginUser($owner);
        $this->postLaneAsStream($client, (string) $project->id, $epicId, '0');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(TurboBundle::STREAM_MEDIA_TYPE, (string) $client->getResponse()->headers->get('Content-Type'));
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="board">', $body);
        self::assertStringNotContainsString('data-lane="'.$epicId.'"', $body);

        $this->postLaneAsStream($client, (string) $project->id, $epicId, '1');
        self::assertStringContainsString('data-lane="'.$epicId.'"', (string) $client->getResponse()->getContent());
    }

    public function test_a_done_epic_shows_as_one_card_and_its_children_leave_the_board(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'lanes-done@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Finished epic', 'done'), CardType::Epic);
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

    public function test_lanes_cost_one_query_whatever_the_number_of_epics(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'lanes-queries@example.com');
        $plain = $this->project($em, $owner, 'plain');
        $this->card($em, $plain, 'Plain');
        $epics = $this->project($em, $owner, 'epics');
        for ($index = 0; $index < 3; ++$index) {
            $epic = $this->typed($em, $this->card($em, $epics, 'Epic '.$index, 'next', $index), CardType::Epic);
            $this->childOf($em, $epic, $this->card($em, $epics, 'Child '.$index, 'backlog'));
            $this->childOf($em, $epic, $this->card($em, $epics, 'Done child '.$index, 'done'));
        }
        $em->clear();
        $client->loginUser($owner);

        $plainReads = $this->boardCardReads($client, (string) $plain->id);
        $epicReads = $this->boardCardReads($client, (string) $epics->id);

        self::assertNotSame([], $plainReads);
        self::assertCount(\count($plainReads), $epicReads);
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
