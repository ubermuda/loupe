<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunToolCall;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\BucketTimeComputer;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunToolCallKind;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes a done card with two Codex runs, each with usage and tool calls of
 * every kind, so the Metrics page shows a codex series next to claude-code.
 */
#[When('dev')]
final readonly class DevCodexRunSeeder
{
    public const string HARNESS = 'codex';

    private const string MODEL = 'gpt-6-sol';

    /** @var list<array{int, string}> days ago and cost of each run */
    private const array RUNS = [[9, '2.400000'], [4, '1.900000']];

    /** @var list<array{string, WorkerRunToolCallKind, int, int, bool, list<string>}> tool, kind, start offset in seconds, duration in milliseconds, in a subagent, signatures */
    private const array CALLS = [
        ['exec', WorkerRunToolCallKind::Shell, 15, 1_200, false, ['git status']],
        ['apply_patch', WorkerRunToolCallKind::Tool, 90, 400, false, ['apply_patch']],
        ['spawn_agent', WorkerRunToolCallKind::Subagent, 150, 240_000, false, ['spawn_agent']],
        ['exec', WorkerRunToolCallKind::Shell, 170, 95_000, true, ['just phpunit']],
        ['exec', WorkerRunToolCallKind::Shell, 420, 150_000, false, ['just phpunit']],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private WorkerRunRepository $workerRuns,
        private BucketTimeComputer $bucketTimes,
    ) {
    }

    /** False when the project already holds a Codex run, so a second run adds nothing. */
    public function seed(Project $project): bool
    {
        if ($this->workerRuns->findOneBy(['project' => $project, 'harness' => self::HARNESS]) instanceof WorkerRun) {
            return false;
        }

        $column = array_find($this->boardColumns->findForProject($project), static fn (BoardColumn $column): bool => 'done' === $column->slug)
            ?? throw new \LogicException('The project has a done column.');
        $createdAt = new \DateTimeImmutable(\sprintf('-%d days', self::RUNS[0][0] + 1));
        $card = new Card(project: $project, column: $column, title: 'Run a worker on Codex', body: '', number: $this->cards->nextNumber($project), type: 'feature', createdAt: $createdAt);
        $this->em->persist($card);
        $this->em->flush();
        $this->cardEvents->record($card, CardEventKind::Created, Actor::Agent, null, [], $createdAt);

        $runs = [];
        foreach (self::RUNS as [$daysAgo, $costUsd]) {
            $runs[] = $this->run($project, $card, new \DateTimeImmutable(\sprintf('-%d days', $daysAgo)), $costUsd);
        }
        $card->completedAt = new \DateTimeImmutable(\sprintf('-%d days +2 hours', self::RUNS[1][0]));
        $this->em->flush();
        $this->bucketTimes->recompute($project, array_map(static fn (WorkerRun $run): Uuid => $run->id ?? throw new \LogicException('A stored run has an id.'), $runs));

        return true;
    }

    /** WorkerRunFactListener writes the fact row on flush, after the tool calls of the same flush. */
    private function run(Project $project, Card $card, \DateTimeImmutable $endedAt, string $costUsd): WorkerRun
    {
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A stored card has an id.'),
            cardNumber: $card->number,
            workKind: 'implement',
            state: WorkerRunState::Succeeded,
            runKey: Uuid::v7(),
            startedAt: $endedAt->modify('-12 minutes'),
            endedAt: $endedAt,
            exitCode: 0,
            hasResult: true,
            output: 'The pull request is open.',
            receivedAt: $endedAt,
        );
        $run->recordHarness(self::HARNESS, 'chatgpt', self::MODEL, null);
        $run->usageSource = WorkerRunUsageSource::Reported;
        $run->toolTimeMs = 391_600;
        $run->idleGapMs = 60_000;
        $run->peakContextTokens = 148_000;
        $this->em->persist($run);
        $tokens = (int) round((float) $costUsd * 60_000);
        $this->em->persist(new WorkerRunUsage($run, $project, $run->subjectType, $run->subjectId, $run->workKind, self::MODEL, WorkerRunUsageSource::Reported, $tokens, intdiv($tokens, 15), $tokens * 5, 0, $costUsd));

        $startedAt = $run->startedAt ?? throw new \LogicException('A seeded run has a start.');
        foreach (self::CALLS as $index => [$tool, $kind, $offset, $durationMs, $inSubagent, $signatures]) {
            $this->em->persist(new WorkerRunToolCall(Uuid::v7(), $run, $index + 1, $tool, $kind, $startedAt->modify(\sprintf('+%d seconds', $offset)), $durationMs, false, $inSubagent, null, null, $signatures, null));
        }

        return $run;
    }
}
