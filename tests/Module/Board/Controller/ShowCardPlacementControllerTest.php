<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Board\Entity\CardType;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Doctrine\ORM\EntityManagerInterface;
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
        $first = $this->card($em, $project, 'First', 'next', 0);
        $second = $this->card($em, $project, 'Second & more', 'next', 1);
        $backlog = $this->column($project, 'backlog');
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
        self::assertSame(0, $counts[(string) $backlog->id]);
        self::assertSame(2, $counts[(string) $next->id]);
        self::assertCount(4, $counts);
    }

    public function test_a_placed_epic_keeps_the_progress_of_its_children(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-epic@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Epic', 'next'), CardType::Epic);
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

    public function test_the_placed_card_keeps_the_warning_of_a_run_that_gave_up(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-warning@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Stuck', 'next');
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            cardId: $card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: $card->number,
            ruleName: 'implement',
            state: WorkerRunState::GaveUp,
            runKey: Uuid::v7(),
            endedAt: new \DateTimeImmutable(),
            exitCode: 0,
            hasResult: true,
            output: 'Tests still fail.',
            receivedAt: new \DateTimeImmutable(),
            cardColumn: 'next',
        );
        $em->persist($run);
        $em->persist(new WorkerRunStateChange($run, WorkerRunState::GaveUp, new \DateTimeImmutable(), new \DateTimeImmutable()));
        $em->flush();
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
        $this->card($em, $project, 'Stays', 'backlog', 0);
        $gone = '01920000-0000-7000-8000-000000000000';
        $backlog = $this->column($project, 'backlog');
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
        self::assertSame(1, $this->counts($content)[(string) $backlog->id]);
    }

    public function test_a_terminal_card_outside_the_window_gets_a_removal(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'placement-old-done@example.com');
        $project = $this->project($em, $owner);
        $old = $this->card($em, $project, 'Long finished', 'done');
        $old->completedAt = new \DateTimeImmutable(\sprintf('-%d days', ShowBoardHandler::TERMINAL_WINDOW_DAYS + 1));
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

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertSame('See all 2 finished cards', trim($crawler->filter('#board-history-'.$done->id)->text()));
    }

    public function test_a_card_of_another_project_gets_a_removal_through_this_project(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $member = $this->user($em, 'placement-cross-member@example.com');
        $stranger = $this->user($em, 'placement-cross-stranger@example.com');
        $mine = $this->project($em, $member);
        $theirs = $this->project($em, $stranger, 'their-app');
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
        $row = $crawler->filter('#board-row-'.$card->id);
        self::assertSame((string) $card->id, $row->attr('data-card-id'));
        self::assertSame((string) $next->id, $row->attr('data-column-id'));

        $digest = (string) $crawler->filter('#board-card-'.$card->id)->attr('data-card-digest');
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $digest);
        self::assertSame($digest, $row->attr('data-card-digest'));

        // The stream renders the same face, so an unchanged card keeps its digest.
        $client->request(Request::METHOD_GET, $url);
        self::assertStringContainsString('data-card-digest="'.$digest.'"', (string) $client->getResponse()->getContent());
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
}
