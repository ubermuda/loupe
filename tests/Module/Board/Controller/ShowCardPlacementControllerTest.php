<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;

final class ShowCardPlacementControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_a_member_gets_one_stream_that_places_the_card(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-member@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $first = $this->card($em, $project, 'First', 'next', 0);
        $second = $this->card($em, $project, 'Second & more', 'next', 1);
        $triage = $this->column($project, 'triage');
        $next = $this->column($project, 'next');
        $url = $this->placementUrl((string) $project->id, (string) $second->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(TurboBundle::STREAM_MEDIA_TYPE, (string) $client->getResponse()->headers->get('Content-Type'));
        $content = (string) $client->getResponse()->getContent();
        self::assertSame(1, substr_count($content, '<turbo-stream'));
        self::assertStringContainsString('action="board-place" target="board-card-'.$second->id.'"', $content);
        self::assertStringContainsString('data-column-id="'.$next->id.'"', $content);
        self::assertStringContainsString('data-after="'.$first->id.'"', $content);
        self::assertStringContainsString('data-row-after="'.$first->id.'"', $content);
        self::assertStringContainsString('id="board-card-'.$second->id.'"', $content);
        self::assertStringContainsString('id="board-row-'.$second->id.'"', $content);
        self::assertStringContainsString('Second &amp; more', $content);
        self::assertStringNotContainsString('data-removed', $content);

        $counts = $this->counts($content);
        self::assertSame(0, $counts[(string) $triage->id]);
        self::assertSame(2, $counts[(string) $next->id]);
        self::assertSame(0, $counts[(string) $this->column($project, 'backlog')->id]);
        self::assertCount(5, $counts);
    }

    public function test_a_placed_epic_keeps_the_progress_of_its_children(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-epic@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Epic', 'next'), CardType::Epic);
        $epic->laneEnabled = false;
        $this->childOf($em, $epic, $this->card($em, $project, 'Open child'));
        $this->childOf($em, $epic, $this->card($em, $project, 'Done child', 'done'));
        $url = $this->placementUrl((string) $project->id, (string) $epic->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        // The placement morphs the face on the page, so a face with no badge would remove it.
        self::assertMatchesRegularExpression('#data-card-progress>\s*1/2 done\s*<#', (string) $client->getResponse()->getContent());
    }

    public function test_a_lane_epic_gets_its_lane_head_and_its_row(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-lane-head@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $before = $this->typed($em, $this->card($em, $project, 'Earlier epic', 'triage'), CardType::Epic);
        $epic = $this->typed($em, $this->card($em, $project, 'Lane epic', 'next'), CardType::Epic);
        $this->childOf($em, $epic, $this->card($em, $project, 'Open child'));
        $this->childOf($em, $epic, $this->card($em, $project, 'Done child', 'done'));
        $url = $this->placementUrl((string) $project->id, (string) $epic->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('#\sdata-lane-head[\s>]#', $content);
        self::assertStringContainsString('data-lane-after="'.$before->id.'"', $content);
        self::assertStringContainsString('class="lp-board-lane__head"', $content);
        self::assertMatchesRegularExpression('#data-lane-progress[^>]*>\s*<progress[^>]*value="1" max="2"[^>]*></progress>\s*1/2\s*<span class="lp-board-lane__done">done</span>#', $content);
        self::assertStringNotContainsString('id="board-card-'.$epic->id.'"', $content);
        self::assertStringContainsString('id="board-row-'.$epic->id.'"', $content);
    }

    public function test_a_lane_epic_in_the_backlog_gets_its_lane_head_and_no_row(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-backlog-lane-head@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Waiting epic', 'backlog'), CardType::Epic);
        $this->childOf($em, $epic, $this->card($em, $project, 'Open child', 'next'));
        $backlog = $this->column($project, 'backlog');
        $url = $this->placementUrl((string) $project->id, (string) $epic->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('#\sdata-lane-head[\s>]#', $content);
        self::assertStringContainsString('data-column-id="'.$backlog->id.'"', $content);
        self::assertStringContainsString('class="lp-board-lane__head"', $content);
        self::assertStringNotContainsString('data-removed', $content);
        self::assertStringNotContainsString('id="board-row-'.$epic->id.'"', $content);
        self::assertSame(1, $this->counts($content)[(string) $backlog->id]);
    }

    public function test_a_card_in_the_backlog_gets_a_removal_and_the_backlog_count(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-backlog-card@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Waiting', 'backlog');
        $this->card($em, $project, 'Waiting too', 'backlog');
        $backlog = $this->column($project, 'backlog');
        $url = $this->placementUrl((string) $project->id, (string) $card->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-removed="1"', $content);
        self::assertSame(2, $this->counts($content)[(string) $backlog->id]);
    }

    public function test_a_child_in_a_lane_names_its_lane_and_skips_the_lane_epic(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-lane-child@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Lane epic', 'next', 0), CardType::Epic);
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'Child', 'next', 1));
        $url = $this->placementUrl((string) $project->id, (string) $child->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-lane="'.$epic->id.'"', $content);
        self::assertStringContainsString('data-after=""', $content);
        self::assertStringContainsString('data-row-after="'.$epic->id.'"', $content);
        self::assertStringNotContainsString('data-lane-head', $content);
        self::assertStringNotContainsString('data-card-parent-tag', $content);
    }

    public function test_a_card_on_a_board_with_no_lane_names_no_lane(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-no-lane@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $card = $this->card($em, $project, 'Plain', 'next');
        $url = $this->placementUrl((string) $project->id, (string) $card->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('data-lane', (string) $client->getResponse()->getContent());
    }

    public function test_the_lane_board_gives_each_lane_cell_and_cell_count_a_hook(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-lane-ids@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Lane epic', 'next', 0), CardType::Epic);
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'Child', 'next', 1));
        $orphan = $this->card($em, $project, 'Orphan', 'triage');
        $next = $this->column($project, 'next');
        $triage = $this->column($project, 'triage');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#board-lane-'.$epic->id.' > .lp-board-lane__head [data-lane-progress]'));
        self::assertCount(1, $crawler->filter('#board-lane-'.$epic->id.' #board-cell-'.$epic->id.'-'.$next->id.' > #board-card-'.$child->id));
        self::assertCount(1, $crawler->filter('#board-cell-other-'.$triage->id.' > #board-card-'.$orphan->id));
        $cellCounts = $crawler->filter('#board-lane-'.$epic->id.' [data-cell-count]')->each(static fn ($node): string => trim($node->text()));
        self::assertSame(['0', '1', '0', '0'], $cellCounts);
        self::assertCount(0, $crawler->filter('#board-group-'.$next->id));
        self::assertCount(1, $crawler->filter('#board-history-'.$this->column($project, 'done')->id));
    }

    public function test_the_placed_card_keeps_the_warning_of_a_run_that_gave_up(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-warning@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $card = $this->card($em, $project, 'Stuck', 'next');
        $run = $this->gaveUp($em, $card);
        $url = $this->placementUrl((string) $project->id, (string) $card->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-card-run-warning="'.$run->id.'"', (string) $client->getResponse()->getContent());
    }

    public function test_an_outsider_is_refused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-owner@example.com');
        $outsider = $this->user($em, 'placement-outsider@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $card = $this->card($em, $project, 'Private');
        $url = $this->placementUrl((string) $project->id, (string) $card->id);
        $em->clear();

        $client->loginUser($outsider);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Private', (string) $client->getResponse()->getContent());
    }

    public function test_an_outsider_learns_nothing_about_a_card_that_does_not_exist(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-owner-gone@example.com');
        $outsider = $this->user($em, 'placement-outsider-gone@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $url = $this->placementUrl((string) $project->id, '01920000-0000-7000-8000-000000000000');
        $em->clear();

        $client->loginUser($outsider);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_deleted_card_gets_a_removal(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-deleted@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $this->card($em, $project, 'Stays', 'triage', 0);
        $gone = '01920000-0000-7000-8000-000000000000';
        $triage = $this->column($project, 'triage');
        $url = $this->placementUrl((string) $project->id, $gone);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertSame(1, substr_count($content, '<turbo-stream'));
        self::assertStringContainsString('action="board-place" target="board-card-'.$gone.'"', $content);
        self::assertStringContainsString('data-removed="1"', $content);
        self::assertStringNotContainsString('lp-board-card"', $content);
        self::assertSame(1, $this->counts($content)[(string) $triage->id]);
    }

    public function test_a_terminal_card_outside_the_window_gets_a_removal(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-old-done@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $old = $this->card($em, $project, 'Long finished', 'done');
        $old->completedAt = new \DateTimeImmutable('-4 days');
        $em->flush();
        $done = $this->column($project, 'done');
        $url = $this->placementUrl((string) $project->id, (string) $old->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-removed="1"', $content);
        self::assertStringNotContainsString('Long finished', $content);
        self::assertSame(0, $this->counts($content)[(string) $done->id]);
    }

    public function test_the_stream_carries_the_history_link_of_each_terminal_column(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-history@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $this->card($em, $project, 'Finished before', 'done');
        $moved = $this->card($em, $project, 'Just finished', 'done');
        $done = $this->column($project, 'done');
        $next = $this->column($project, 'next');
        $url = $this->placementUrl((string) $project->id, (string) $moved->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $history = $this->history((string) $client->getResponse()->getContent());
        self::assertSame(['See all 2 finished cards'], array_values($history));
        self::assertArrayHasKey((string) $done->id, $history);
        self::assertArrayNotHasKey((string) $next->id, $history);
        self::assertSame(1, preg_match('/data-history-totals="([^"]*)"/', (string) $client->getResponse()->getContent(), $match));
        self::assertSame([(string) $done->id => 2], json_decode(html_entity_decode($match[1] ?? ''), true, flags: \JSON_THROW_ON_ERROR));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        $link = $crawler->filter('#board-history-'.$done->id);
        self::assertSame('See all 2 finished cards', trim($link->text()));
        self::assertSame('2', $link->attr('data-history-total'));
    }

    public function test_a_card_of_another_project_gets_a_removal_through_this_project(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $member = $this->user($em, 'placement-cross-member@example.com');
        $stranger = $this->user($em, 'placement-cross-stranger@example.com');
        $mine = $this->project($em, $member);
        $this->addTriageColumn($mine);
        $em->flush();
        $theirs = $this->project($em, $stranger, 'their-app');
        $this->addTriageColumn($theirs);
        $em->flush();
        $foreign = $this->card($em, $theirs, 'Their secret plan', 'next');
        $url = $this->placementUrl((string) $mine->id, (string) $foreign->id);
        $em->clear();

        $client->loginUser($member);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('action="board-place" target="board-card-'.$foreign->id.'"', $content);
        self::assertStringContainsString('data-removed="1"', $content);
        self::assertStringNotContainsString('Their secret plan', $content);
        self::assertStringNotContainsString('lp-board-card"', $content);
    }

    public function test_the_placement_is_not_found_while_the_board_is_off(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'placement-flag-off@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $card = $this->card($em, $project, 'Hidden');
        $url = $this->placementUrl((string) $project->id, (string) $card->id);
        $this->disableBoard();
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_board_page_gives_each_card_and_row_a_stable_id_and_digest(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-ids@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $card = $this->card($em, $project, 'Identified', 'next', 0);
        $next = $this->column($project, 'next');
        $url = $this->placementUrl((string) $project->id, (string) $card->id);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('section#board-column-'.$next->id));
        self::assertCount(1, $crawler->filter('#board-group-'.$next->id.' > #board-card-'.$card->id));
        self::assertSame('1', $crawler->filter('#board-count-'.$next->id)->text());
        $row = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/list')->filter('#board-row-'.$card->id);
        self::assertSame((string) $card->id, $row->attr('data-card-id'));
        self::assertSame((string) $next->id, $row->attr('data-column-id'));

        $digest = (string) $crawler->filter('#board-card-'.$card->id)->attr('data-card-digest');
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $digest);
        self::assertSame($digest, $row->attr('data-card-digest'));

        // The stream renders the same face, so an unchanged card keeps its digest.
        $client->request(Request::METHOD_GET, $url);
        self::assertStringContainsString('data-card-digest="'.$digest.'"', (string) $client->getResponse()->getContent());
    }

    public function test_the_stream_digest_of_a_card_with_a_parent_is_the_page_digest(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-digest-child@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Closed epic', 'next'), CardType::Epic);
        $epic->laneEnabled = false;
        $em->flush();
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'Child', 'triage'));
        $em->clear();

        $client->loginUser($owner);

        self::assertSame($this->pageDigest($client, $child), $this->streamDigest($client, $child));
    }

    public function test_the_stream_digest_of_an_epic_with_progress_and_a_warning_is_the_page_digest(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-digest-epic@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Closed epic', 'next'), CardType::Epic);
        $epic->laneEnabled = false;
        $em->flush();
        $this->childOf($em, $epic, $this->card($em, $project, 'Open child'));
        $this->childOf($em, $epic, $this->card($em, $project, 'Done child', 'done'));
        $run = $this->gaveUp($em, $epic);
        $em->clear();

        $client->loginUser($owner);
        $stream = $this->streamDigest($client, $epic);

        $content = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('#data-card-progress>\s*1/2 done\s*<#', $content);
        self::assertStringContainsString('data-card-run-warning="'.$run->id.'"', $content);
        self::assertSame($this->pageDigest($client, $epic), $stream);
    }

    public function test_a_card_on_a_board_with_lanes_is_placed_in_its_lane_cell(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-lane@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Epic', 'next'), CardType::Epic);
        $this->card($em, $project, 'Other first', 'triage', 0);
        $firstChild = $this->childOf($em, $epic, $this->card($em, $project, 'Child first', 'triage', 1));
        $otherSecond = $this->card($em, $project, 'Other second', 'triage', 2);
        $secondChild = $this->childOf($em, $epic, $this->card($em, $project, 'Child second', 'triage', 3));
        $url = $this->placementUrl((string) $project->id, (string) $secondChild->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-lane="'.$epic->id.'"', $content);
        self::assertStringContainsString('data-after="'.$firstChild->id.'"', $content);
        self::assertStringContainsString('data-row-after="'.$otherSecond->id.'"', $content);
        self::assertStringNotContainsString('data-lane-head', $content);
        self::assertStringNotContainsString('data-card-parent-tag', $this->face($content));
    }

    public function test_a_card_of_the_other_row_keeps_its_parent_tag(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-lane-other@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $this->typed($em, $this->card($em, $project, 'Lane epic', 'next'), CardType::Epic);
        $closed = $this->typed($em, $this->card($em, $project, 'Closed epic', 'next', 1), CardType::Epic);
        $closed->laneEnabled = false;
        $em->flush();
        $child = $this->childOf($em, $closed, $this->card($em, $project, 'Child', 'triage'));
        $url = $this->placementUrl((string) $project->id, (string) $child->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-lane="other"', $content);
        self::assertStringContainsString('data-card-parent-tag', $this->face($content));
    }

    public function test_a_lane_epic_is_marked_as_a_lane_head(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-lane-epic@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Epic', 'next'), CardType::Epic);
        $url = $this->placementUrl((string) $project->id, (string) $epic->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('#\sdata-lane-head[\s>]#', $content);
        self::assertStringNotContainsString('data-lane="', $content);
        self::assertStringNotContainsString('id="board-card-'.$epic->id.'"', $content);
    }

    public function test_a_removal_on_a_board_with_lanes_leaves_the_cell_counts_to_the_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-lane-removed@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $this->typed($em, $this->card($em, $project, 'Epic', 'next'), CardType::Epic);
        $this->card($em, $project, 'Stays', 'triage');
        $triage = $this->column($project, 'triage');
        $url = $this->placementUrl((string) $project->id, '01920000-0000-7000-8000-000000000000');
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-removed="1"', $content);
        self::assertStringNotContainsString('data-lane', $content);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertCount(1, $crawler->filter('#board-cell-other-'.$triage->id.' .lp-board-card'));
        self::assertCount(1, $crawler->filter('#board-history-'.$this->column($project, 'done')->id));
    }

    public function test_a_board_with_no_lane_carries_no_lane_data(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-no-lane@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $card = $this->card($em, $project, 'Plain', 'next');
        $url = $this->placementUrl((string) $project->id, (string) $card->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('data-lane', (string) $client->getResponse()->getContent());
    }

    private function gaveUp(EntityManagerInterface $em, Card $card): WorkerRun
    {
        $run = new WorkerRun(
            project: $card->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: $card->number,
            workKind: 'implement',
            state: WorkerRunState::GaveUp,
            runKey: Uuid::v7(),
            endedAt: new \DateTimeImmutable(),
            exitCode: 0,
            hasResult: true,
            output: 'Tests still fail.',
            receivedAt: new \DateTimeImmutable(),
        );
        $em->persist($run);
        $em->persist(new WorkerRunStateChange($run, WorkerRunState::GaveUp, new \DateTimeImmutable(), new \DateTimeImmutable()));
        $em->flush();

        return $run;
    }

    private function pageDigest(KernelBrowser $client, Card $card): string
    {
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$card->project->id.'/board');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('#board-card-'.$card->id)->attr('data-card-digest');
    }

    private function streamDigest(KernelBrowser $client, Card $card): string
    {
        $client->request(Request::METHOD_GET, $this->placementUrl((string) $card->project->id, (string) $card->id));
        self::assertResponseIsSuccessful();
        self::assertSame(1, preg_match('/data-card-digest="([0-9a-f]{12})"/', $this->face((string) $client->getResponse()->getContent()), $match));

        return $match[1] ?? '';
    }

    private function placementUrl(string $projectId, string $cardId): string
    {
        return '/projects/'.$projectId.'/board/cards/'.$cardId.'/placement';
    }

    /** @return array<string, int> */
    private function counts(string $content): array
    {
        self::assertSame(1, preg_match('/data-counts="([^"]*)"/', $content, $match));
        $counts = json_decode(html_entity_decode($match[1] ?? ''), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($counts);

        /* @var array<string, int> $counts */
        return $counts;
    }

    /** @return array<string, string> */
    private function history(string $content): array
    {
        self::assertSame(1, preg_match('/data-history="([^"]*)"/', $content, $match));
        $history = json_decode(html_entity_decode($match[1] ?? ''), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($history);

        /* @var array<string, string> $history */
        return $history;
    }

    /** The card face of the stream, without its list row. */
    private function face(string $content): string
    {
        self::assertSame(1, preg_match('#<article class="lp-board-card.*?</article>#s', $content, $match));

        return $match[0] ?? '';
    }
}
