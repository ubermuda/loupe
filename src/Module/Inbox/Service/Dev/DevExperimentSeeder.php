<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Repository\ExperimentDefinitionRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes an experiment that compares two models on the implement work, with
 * cards, pins and costed runs, so the Comparison and Cards tabs show figures.
 *
 * It lives in Inbox, which may name a card and a worker run. The root
 * namespace may not name a card, as `phparkitect.php` says.
 */
#[When('dev')]
final readonly class DevExperimentSeeder
{
    public const string EXPERIMENT = 'implement';

    private const array MODELS = ['opus' => 'claude-opus-5-5', 'sonnet' => 'claude-sonnet-5-5'];

    /** The last card has a pin and no run, so the figures leave it out. */
    private const array CARDS = [
        ['Export the board as CSV', 'opus', CardType::Feature, 'done', true, [], [['4.200000', 32]]],
        ['Fix the empty search state', 'opus', CardType::Bug, 'done', true, [], [['3.800000', 28]]],
        ['Show the run cost on a card', 'opus', CardType::Feature, 'done', true, ['conflict'], [['3.100000', 26], ['2.000000', 14]]],
        ['Retry a lost webhook', 'opus', CardType::Bug, 'done', true, [], [['4.600000', 35]]],
        ['Sort the inbox by due date', 'opus', CardType::Feature, 'done', true, [], [['3.900000', 30]]],
        ['Link a document to a card', 'opus', CardType::Feature, 'done', true, [], [['4.400000', 38]]],
        ['Archive a finished epic', 'opus', CardType::Feature, 'in-progress', false, [], [['4.000000', 31]]],
        ['Paginate the audit log', 'sonnet', CardType::Feature, 'done', true, [], [['1.600000', 41]]],
        ['Fix a stale badge count', 'sonnet', CardType::Bug, 'done', true, ['checks-failed'], [['1.200000', 33], ['0.700000', 18]]],
        ['Rename a board column', 'sonnet', CardType::Feature, 'done', true, [], [['1.400000', 37]]],
        ['Mute a noisy bridge', 'sonnet', CardType::Feature, 'done', true, ['conflict', 'checks-failed'], [['1.500000', 44], ['0.600000', 21]]],
        ['Show the merge time on a card', 'sonnet', CardType::Feature, 'done', true, [], [['1.700000', 39]]],
        ['Fix the dark mode chart colours', 'sonnet', CardType::Bug, 'done', false, [], [['1.500000', 52]]],
        ['Filter the runs by bridge', 'sonnet', CardType::Feature, 'in-progress', false, [], [['1.800000', 47]]],
        ['Import cards from a CSV file', 'sonnet', CardType::Feature, 'next', false, [], []],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private ExperimentDefinitionRepository $experimentDefinitions,
    ) {
    }

    /** False when the project already holds the experiment, so a second run adds nothing. */
    public function seed(Project $project): bool
    {
        if ($this->experimentDefinitions->findOneBy(['project' => $project, 'experiment' => self::EXPERIMENT]) instanceof ExperimentDefinition) {
            return false;
        }

        $definition = new ExperimentDefinition($project, self::EXPERIMENT, [['name' => 'opus', 'weight' => 1], ['name' => 'sonnet', 'weight' => 1]]);
        $definition->metrics = ['cost', 'merge-rate', 'duration', 'input-tokens', 'tool-time'];
        $this->em->persist($definition);

        $columns = [];
        foreach ($this->boardColumns->findForProject($project) as $column) {
            $columns[$column->slug] = $column;
        }
        $number = $this->cards->nextNumber($project);
        $at = new \DateTimeImmutable('-20 days');
        foreach (self::CARDS as $index => [$title, $variant, $type, $slug, $merged, $fixReasons, $runs]) {
            $column = $columns[$slug] ?? throw new \LogicException(\sprintf('The project has a %s column.', $slug));
            $card = $this->card($project, $column, $title, $type, $number + $index, $at->modify('-10 days'));
            $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
            $this->em->persist(new ExperimentPin($project, $cardId, self::EXPERIMENT, $variant, $at, $at));

            foreach ($runs as [$cost, $minutes]) {
                $at = $at->modify('+20 hours');
                $this->run($project, $card, $variant, $at, $minutes, $cost);
            }
            foreach ($fixReasons as $reason) {
                $this->cardEvents->record($card, CardEventKind::FixRequested, CardReporter::System, null, ['reason' => $reason], $at->modify('+1 hour'));
            }
            if ($merged) {
                $this->cardEvents->record($card, CardEventKind::Moved, CardReporter::System, null, ['from' => [], 'to' => [], 'cause' => ['type' => 'merged', 'pullRequest' => $card->number]], $at->modify('+2 hours'));
            }
            if ($column->terminal) {
                $card->completedAt = $at->modify('+2 hours');
            }
        }
        $this->em->flush();

        return true;
    }

    private function card(Project $project, BoardColumn $column, string $title, CardType $type, int $number, \DateTimeImmutable $createdAt): Card
    {
        $card = new Card(project: $project, column: $column, title: $title, body: '', number: $number, type: $type, createdAt: $createdAt);
        $this->em->persist($card);
        $this->em->flush();
        $this->cardEvents->record($card, CardEventKind::Created, CardReporter::Agent, null, [], $createdAt);

        return $card;
    }

    /** WorkerRunFactListener writes the fact row of the run on flush. */
    private function run(Project $project, Card $card, string $variant, \DateTimeImmutable $endedAt, int $minutes, string $costUsd): void
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
            startedAt: $endedAt->modify(\sprintf('-%d minutes', $minutes)),
            endedAt: $endedAt,
            exitCode: 0,
            hasResult: true,
            output: 'The pull request is open.',
            receivedAt: $endedAt,
        );
        $run->experiment = self::EXPERIMENT;
        $run->variant = $variant;
        $run->requestedModel = self::MODELS[$variant];
        $run->usageSource = WorkerRunUsageSource::Reported;
        $this->em->persist($run);
        $tokens = (int) round((float) $costUsd * 40_000);
        $this->em->persist(new WorkerRunUsage($run, $project, $run->subjectType, $run->subjectId, $run->workKind, self::MODELS[$variant], WorkerRunUsageSource::Reported, $tokens, intdiv($tokens, 20), $tokens * 6, intdiv($tokens, 3), $costUsd));
    }
}
