<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Service\BoardCardReportSource;
use App\Module\Bridge\Experiment\CardColumn;
use App\Module\Bridge\Experiment\CardOutcome;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BoardCardReportSourceTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardReportSourceInterface $source;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $source = self::getContainer()->get(CardReportSourceInterface::class);
        self::assertInstanceOf(BoardCardReportSource::class, $source);
        $this->source = $source;
    }

    public function test_it_returns_the_columns_of_the_projects_cards_only(): void
    {
        $project = $this->makeProject('card-report');
        $other = $this->makeProject('card-report-other');
        $open = $this->card($project, 1, 'in-progress');
        $finished = $this->card($project, 2, 'done');
        $foreign = $this->card($other, 1, 'done');
        $this->em->clear();

        $columns = $this->source->columnsFor($project, [$open, $finished, $foreign, Uuid::v7()]);

        $expected = [
            (string) $open => new CardColumn('board.card.status.in-progress', false),
            (string) $finished => new CardColumn('board.card.status.done', true),
        ];
        ksort($expected);
        ksort($columns);
        self::assertEquals($expected, $columns);
    }

    public function test_it_returns_nothing_for_no_ids(): void
    {
        self::assertSame([], $this->source->columnsFor($this->makeProject('card-report-empty'), []));
    }

    public function test_it_reads_the_outcome_of_each_card_from_its_history_and_pull_requests(): void
    {
        $project = $this->makeProject('card-outcome');
        $other = $this->makeProject('card-outcome-other');
        $merged = $this->card($project, 1, 'done');
        $handMoved = $this->card($project, 2, 'done');
        $quiet = $this->card($project, 3, 'in-progress');
        $engineMerged = $this->card($project, 4, 'done');
        $foreign = $this->card($other, 1, 'done');

        $first = new \DateTimeImmutable('2026-09-01 09:00:00');
        $this->event($merged, CardEventKind::Created, [], $first);
        $this->event($engineMerged, CardEventKind::Moved, ['from' => [], 'to' => [], 'cause' => ['type' => 'workflow-rule', 'rule' => 'merged']], $first->modify('+3 days'));
        $this->event($quiet, CardEventKind::Moved, ['from' => [], 'to' => [], 'cause' => ['type' => 'workflow-rule', 'rule' => 'reviewable']], $first->modify('+3 days'));
        $this->event($merged, CardEventKind::FixRequested, ['reason' => 'conflict', 'pullRequest' => 7], $first->modify('+1 hour'));
        $this->event($merged, CardEventKind::FixRequested, ['reason' => 'checks-failed', 'pullRequest' => 7], $first->modify('+2 hours'));
        $this->event($merged, CardEventKind::FixRequested, ['reason' => 'checks-failed', 'pullRequest' => 8], $first->modify('+3 hours'));
        $this->event($merged, CardEventKind::Stopped, ['reason' => 'conflict', 'pullRequest' => 7], $first->modify('+4 hours'));
        $this->event($merged, CardEventKind::Moved, ['from' => [], 'to' => [], 'cause' => ['type' => 'checks-passed', 'pullRequest' => 8]], $first->modify('+5 hours'));
        $this->event($merged, CardEventKind::Moved, ['from' => [], 'to' => [], 'cause' => ['type' => 'merged', 'pullRequest' => 8]], $first->modify('+6 hours'));
        $this->event($handMoved, CardEventKind::Moved, ['from' => [], 'to' => [], 'cause' => null], $first->modify('+1 day'));
        $this->event($handMoved, CardEventKind::Moved, ['cause' => 'merged'], $first->modify('+2 days'));
        $this->event($foreign, CardEventKind::FixRequested, ['reason' => 'conflict', 'pullRequest' => 1], $first->modify('-1 day'));

        $this->pullRequest($project, $merged, 7, new \DateTimeImmutable('2026-09-01 10:00:00'), null);
        $this->pullRequest($project, $merged, 8, new \DateTimeImmutable('2026-09-01 12:00:00'), new \DateTimeImmutable('2026-09-02 16:00:00'));
        $this->pullRequest($other, $foreign, 7, new \DateTimeImmutable('2026-08-01 10:00:00'), new \DateTimeImmutable('2026-08-01 11:00:00'));
        $this->em->clear();

        $outcomes = $this->source->outcomesFor($project, [$merged, $handMoved, $quiet, $engineMerged, $foreign, Uuid::v7()]);

        $expected = [
            (string) $merged => new CardOutcome(
                fixRounds: ['checks-failed' => 2, 'conflict' => 1],
                merged: true,
                openedAt: new \DateTimeImmutable('2026-09-01 10:00:00'),
                mergedAt: new \DateTimeImmutable('2026-09-02 16:00:00'),
            ),
            (string) $handMoved => new CardOutcome(),
            (string) $quiet => new CardOutcome(),
            (string) $engineMerged => new CardOutcome(merged: true),
        ];
        ksort($expected);
        ksort($outcomes);
        self::assertEquals($expected, $outcomes);
        self::assertSame(3, $outcomes[(string) $merged]->totalFixRounds());
        self::assertSame(30.0, $outcomes[(string) $merged]->hoursToMerge());
        self::assertNull($outcomes[(string) $handMoved]->hoursToMerge());
    }

    public function test_it_returns_the_types_of_the_projects_cards_only(): void
    {
        $project = $this->makeProject('card-types');
        $other = $this->makeProject('card-types-other');
        $feature = $this->card($project, 1, 'done');
        $bug = $this->card($project, 2, 'done', 'bug');
        $foreign = $this->card($other, 1, 'done', 'docs');
        $this->em->clear();

        $types = $this->source->typesFor($project, [$feature, $bug, $foreign, Uuid::v7()]);

        ksort($types);
        $expected = [(string) $feature => 'feature', (string) $bug => 'bug'];
        ksort($expected);
        self::assertSame($expected, $types);
        self::assertSame([], $this->source->typesFor($project, []));
    }

    public function test_the_history_starts_at_the_first_event_of_the_project(): void
    {
        $project = $this->makeProject('history-start');
        $other = $this->makeProject('history-start-other');
        self::assertNull($this->source->historyStartFor($project));

        $card = $this->card($project, 1, 'in-progress');
        $this->event($card, CardEventKind::Moved, [], new \DateTimeImmutable('2026-09-03 08:00:00'));
        $this->event($card, CardEventKind::Created, [], new \DateTimeImmutable('2026-09-02 08:00:00'));
        $this->event($this->card($other, 1, 'in-progress'), CardEventKind::Created, [], new \DateTimeImmutable('2026-01-01 08:00:00'));
        $this->em->clear();

        self::assertEquals(new \DateTimeImmutable('2026-09-02 08:00:00'), $this->source->historyStartFor($project));
    }

    /** @param array<string, mixed> $detail */
    private function event(Uuid $cardId, CardEventKind $kind, array $detail, \DateTimeImmutable $at): void
    {
        $card = $this->em->find(Card::class, $cardId) ?? throw new \LogicException('The card exists.');
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $events->record($card, $kind, CardReporter::System, null, $detail, $at);
        $this->em->flush();
    }

    private function pullRequest(Project $project, Uuid $cardId, int $number, \DateTimeImmutable $openedAt, ?\DateTimeImmutable $mergedAt): void
    {
        $card = $this->em->find(Card::class, $cardId) ?? throw new \LogicException('The card exists.');
        $this->em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/'.$number, Forge::GitHub, 'Acme/Widgets', $number));
        $row = new ForgePullRequest($project, Forge::GitHub->value, 'Acme/Widgets', $number);
        $row->openedAt = $openedAt;
        $row->mergedAt = $mergedAt;
        $this->em->persist($row);
        $this->em->flush();
    }

    private function card(Project $project, int $number, string $column, string $type = 'feature'): Uuid
    {
        $card = new Card(project: $project, column: $this->column($project, $column), title: 'Card '.$number, body: '', number: $number, type: $type);
        $this->em->persist($card);
        $this->em->flush();

        return $card->id ?? throw new \LogicException('The card has no id after a flush.');
    }
}
