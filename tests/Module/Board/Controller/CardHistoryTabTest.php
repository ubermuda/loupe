<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class CardHistoryTabTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_history_tab_lists_the_rows_newest_first(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'history-rows@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Remember everything');
        $backlog = CardEvent::columnDetail($this->column($project, 'backlog'));
        $next = CardEvent::columnDetail($this->column($project, 'next'));
        $events = $this->events();
        $events->record($card, CardEventKind::Created, Actor::Human, $owner, ['column' => $backlog], new \DateTimeImmutable('-3 hours'));
        $events->record($card, CardEventKind::Moved, Actor::System, null, ['from' => $backlog, 'to' => $next, 'cause' => CardEventCause::merged(12)->detail()], new \DateTimeImmutable('-2 hours'));
        $events->record($card, CardEventKind::FixRequested, Actor::System, null, ['reason' => 'checks-failed', 'pullRequest' => 12], new \DateTimeImmutable('-1 hour'));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($card));

        self::assertResponseIsSuccessful();
        self::assertSame('History', trim($crawler->filter('#card-tab-history')->text()));
        $rows = $crawler->filter('#card-panel-history [data-card-history-entry]');
        self::assertCount(3, $rows);
        self::assertStringContainsString('Loupe asked for a fix on #12.', $rows->eq(0)->text());
        self::assertStringContainsString('Because the checks fail.', $rows->eq(0)->text());
        self::assertStringContainsString('Loupe moved it from Backlog to Next.', $rows->eq(1)->text());
        self::assertStringContainsString('After #12 merged.', $rows->eq(1)->text());
        self::assertStringContainsString('Riley Chen created it in Backlog.', $rows->eq(2)->text());
        self::assertSame('3h ago', trim($rows->eq(2)->filter('time')->text()));
        self::assertCount(0, $crawler->filter('#card-panel-history turbo-frame'));
        self::assertCount(0, $crawler->filter('[data-card-history-empty]'));
    }

    public function test_a_card_with_no_rows_says_so(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'history-empty@example.com');
        $card = $this->card($em, $this->project($em, $owner), 'Nothing yet');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($card));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-card-history-entry]'));
        self::assertSame('No history is recorded yet.', trim($crawler->filter('#card-panel-history [data-card-history-empty]')->text()));
    }

    public function test_older_rows_load_in_a_frame_in_place_of_the_link(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'history-older@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'A long story');
        $backlog = CardEvent::columnDetail($this->column($project, 'backlog'));
        $events = $this->events();
        $events->record($card, CardEventKind::Created, Actor::Human, $owner, ['column' => $backlog], new \DateTimeImmutable('-100 minutes'));
        for ($i = 50; $i >= 1; --$i) {
            $events->record($card, CardEventKind::ReadyToMerge, Actor::System, null, ['pullRequest' => $i], new \DateTimeImmutable('-'.$i.' minutes'));
        }
        $em->flush();
        $em->clear();

        $lastShown = $this->events()->findForCard($card)[49];

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($card));

        self::assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('#card-panel-history [data-card-history-entry]'));
        self::assertStringNotContainsString('created it', $crawler->filter('#card-panel-history')->text());
        self::assertSame(['pullRequest' => 50], $lastShown->detail);
        $frameId = 'card-history-older-'.$lastShown->id;
        $frame = $crawler->filter('#card-panel-history turbo-frame#'.$frameId);
        self::assertCount(1, $frame);
        $link = $frame->filter('a[data-card-history-older]');
        self::assertSame('Show older', trim($link->text()));
        parse_str((string) parse_url((string) $link->attr('href'), \PHP_URL_QUERY), $query);
        self::assertSame(['before' => $lastShown->occurredAt->format('Y-m-d\TH:i:s.uP'), 'beforeId' => (string) $lastShown->id], $query);

        $older = $client->request(Request::METHOD_GET, (string) $link->attr('href'));

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('<turbo-frame id="'.$frameId.'"', trim((string) $client->getResponse()->getContent()));
        $root = $older->filter('turbo-frame')->first();
        self::assertSame($frameId, $root->attr('id'));
        $rows = $root->filter('[data-card-history-entry]');
        self::assertCount(1, $rows);
        self::assertStringContainsString('Riley Chen created it in Backlog.', $rows->text());
        self::assertCount(1, $older->filter('turbo-frame'));
    }

    public function test_a_row_written_after_the_first_page_does_not_repeat_on_the_older_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'history-shift@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'A busy story');
        $backlog = CardEvent::columnDetail($this->column($project, 'backlog'));
        $events = $this->events();
        $events->record($card, CardEventKind::Created, Actor::Human, $owner, ['column' => $backlog], new \DateTimeImmutable('-100 minutes'));
        for ($i = 50; $i >= 1; --$i) {
            $events->record($card, CardEventKind::ReadyToMerge, Actor::System, null, ['pullRequest' => $i], new \DateTimeImmutable('-'.$i.' minutes'));
        }
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($card));
        $href = (string) $crawler->filter('#card-panel-history a[data-card-history-older]')->attr('href');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $card = $em->find(Card::class, $card->id);
        self::assertInstanceOf(Card::class, $card);
        $this->events()->record($card, CardEventKind::ReadyToMerge, Actor::System, null, ['pullRequest' => 99], new \DateTimeImmutable());
        $em->flush();

        $older = $client->request(Request::METHOD_GET, $href);

        self::assertResponseIsSuccessful();
        $rows = $older->filter('[data-card-history-entry]');
        self::assertCount(1, $rows);
        self::assertStringContainsString('Riley Chen created it in Backlog.', $rows->text());
    }

    public function test_rows_that_share_one_instant_page_by_id_with_no_repeat_and_no_gap(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'history-ties@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'All at once');
        $at = new \DateTimeImmutable('-1 hour');
        $events = $this->events();
        for ($i = 1; $i <= 52; ++$i) {
            $events->record($card, CardEventKind::ReadyToMerge, Actor::System, null, ['pullRequest' => $i], $at);
        }
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($card));
        $first = $this->pullRequestsOf($crawler->filter('#card-panel-history [data-card-history-entry]')->each(static fn ($row): string => $row->text()));
        $older = $client->request(Request::METHOD_GET, (string) $crawler->filter('#card-panel-history a[data-card-history-older]')->attr('href'));
        $second = $this->pullRequestsOf($older->filter('[data-card-history-entry]')->each(static fn ($row): string => $row->text()));

        self::assertCount(50, $first);
        self::assertCount(2, $second);
        $all = [...$first, ...$second];
        sort($all);
        self::assertSame(range(1, 52), $all);
    }

    public function test_the_older_page_refuses_a_missing_or_malformed_cursor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'history-cursor@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Bad cursor');
        $em->clear();
        $url = $this->cardUrl($card).'/history';
        $id = (string) Uuid::v7();

        $client->loginUser($owner);
        foreach ([
            '',
            '?before='.rawurlencode('2026-09-30T10:00:00.000000+00:00'),
            '?beforeId='.$id,
            '?before=yesterday&beforeId='.$id,
            '?before='.rawurlencode('2026-09-30T10:00:00+00:00').'&beforeId='.$id,
            '?before='.rawurlencode('2026-09-30T10:00:00.000000+00:00').'&beforeId=not-a-uuid',
            '?before=%00&beforeId='.$id,
            '?offset=50',
        ] as $query) {
            $client->request(Request::METHOD_GET, $url.$query);
            self::assertResponseStatusCodeSame(404, $query);
        }

        $client->request(Request::METHOD_GET, $url.'?before='.rawurlencode('2026-09-30T10:00:00.000000+00:00').'&beforeId='.$id);
        self::assertResponseIsSuccessful();
    }

    public function test_another_user_cannot_read_the_history(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'history-owner@example.com');
        $stranger = $this->user($em, 'history-stranger@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Private past');
        $this->events()->record($card, CardEventKind::Created, Actor::Human, $owner, ['column' => CardEvent::columnDetail($this->column($project, 'backlog'))]);
        $em->flush();
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $this->cardUrl($card).'/history');

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('created it', (string) $client->getResponse()->getContent());
    }

    public function test_a_card_of_another_project_is_not_found_under_this_project(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'history-victim@example.com');
        $intruder = $this->user($em, 'history-intruder@example.com');
        $project = $this->project($em, $owner);
        $ownProject = $this->project($em, $intruder, 'intruder-app');
        $card = $this->card($em, $project, 'Someone else\'s past');
        $this->events()->record($card, CardEventKind::Created, Actor::Human, $owner, ['column' => CardEvent::columnDetail($this->column($project, 'backlog'))]);
        $em->flush();
        $em->clear();

        $client->loginUser($intruder);
        $client->request(Request::METHOD_GET, '/projects/'.$ownProject->id.'/board/cards/'.$card->id.'/history');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('created it', (string) $client->getResponse()->getContent());
    }

    public function test_a_finished_run_links_to_the_run_until_the_run_is_purged(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'history-run@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Run it');
        $endedAt = new \DateTimeImmutable('-10 minutes');
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A stored card has an id.'),
            cardNumber: $card->number,
            workKind: 'implement',
            state: WorkerRunState::Succeeded,
            runKey: Uuid::v4(),
            startedAt: $endedAt->modify('-192 seconds'),
            endedAt: $endedAt,
            exitCode: 0,
            hasResult: true,
            receivedAt: $endedAt,
        );
        $em->persist($run);
        $em->flush();
        $runId = (string) $run->id;
        $this->events()->record($card, CardEventKind::RunFinished, Actor::Agent, $owner, [
            'runId' => $runId,
            'ruleName' => 'implement',
            'state' => 'succeeded',
            'interactive' => false,
            'startedAt' => null,
            'endedAt' => null,
            'durationSeconds' => 192,
            'resumeIndex' => 0,
            'resumeCap' => 3,
        ], $endedAt);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($card));

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#card-panel-history [data-card-history-entry]');
        self::assertCount(1, $row);
        self::assertStringContainsString('Agent for Riley Chen ran the implement work.', $row->text());
        self::assertStringContainsString('3m 12s', $row->text());
        self::assertSame('Succeeded', trim($row->filter('.lp-status-chip--ok')->text()));
        $link = $row->filter('a[data-card-history-run="'.$runId.'"]');
        self::assertCount(1, $link);
        self::assertSame('/projects/'.$project->id.'/worker-runs?search='.$runId, $link->attr('href'));
        self::assertSame('_top', $link->attr('data-turbo-frame'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('DELETE FROM bridge_worker_runs WHERE id = ?', [$runId]);

        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($card));

        $row = $crawler->filter('#card-panel-history [data-card-history-entry]');
        self::assertCount(1, $row);
        self::assertStringContainsString('Agent for Riley Chen ran the implement work.', $row->text());
        self::assertCount(0, $row->filter('a[data-card-history-run]'));
    }

    public function test_a_finished_command_run_says_it_is_a_command(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'history-command@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Sync it');
        $this->events()->record($card, CardEventKind::RunFinished, Actor::Agent, $owner, [
            'runId' => (string) Uuid::v4(),
            'ruleName' => 'sync',
            'state' => 'failed',
            'interactive' => false,
            'command' => true,
            'startedAt' => null,
            'endedAt' => null,
            'durationSeconds' => 4,
            'resumeIndex' => null,
            'resumeCap' => null,
        ], new \DateTimeImmutable('-10 minutes'));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->cardUrl($card));

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#card-panel-history [data-card-history-entry]');
        self::assertCount(1, $row);
        self::assertSame('Command', $row->filter('[data-worker-run-command]')->text());
    }

    private function events(): CardEventRepository
    {
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);

        return $events;
    }

    /**
     * @param list<string> $texts
     *
     * @return list<int>
     */
    private function pullRequestsOf(array $texts): array
    {
        return array_map(static fn (string $text): int => preg_match('/#(\d+) is ready to merge/', $text, $m) ? (int) $m[1] : 0, $texts);
    }

    private function cardUrl(Card $card): string
    {
        return '/projects/'.$card->project->id.'/board/cards/'.$card->id;
    }
}
