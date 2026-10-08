<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Mcp;

use App\Module\Board\Entity\CardEventKind;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Experiment\Stats;
use App\Module\Bridge\Mcp\ExperimentGetTool;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\ExperimentScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExperimentGetToolTest extends KernelTestCase
{
    use ExperimentScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_answers_the_comparison_and_the_cards_of_an_experiment(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get@example.com'));
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 3], ['name' => 'b', 'weight' => 1]]);
        $definition->metrics = ['cost', 'merge-rate', 'no-such-metric'];
        $em->persist($definition);
        for ($i = 1; $i <= 5; ++$i) {
            $card = $this->experimentCard($em, $project, $i, merged: true);
            $this->seedUsage($em, $this->experimentRun($em, $project, $card, 'a', state: WorkerRunState::Failed), costUsd: '2.000000');
            $card = $this->experimentCard($em, $project, $i + 5, merged: true);
            $this->seedUsage($em, $this->experimentRun($em, $project, $card, 'b'), costUsd: '1.000000');
        }
        $switched = $this->experimentCard($em, $project, 11);
        $this->experimentRun($em, $project, $switched, 'b', switchedFrom: 'a', at: '-1 hour');
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool()('model-test');

        self::assertSame('model-test', $result['experiment']);
        self::assertSame(['cost', 'merge-rate', 'no-such-metric'], $result['declaredMetrics']);
        self::assertSame(['no-such-metric'], $result['unknownMetrics']);
        self::assertSame(Stats::MIN_FINISHED_CARDS, $result['minFinishedCards']);
        self::assertSame(['cheaperVariant' => 'b', 'saving' => 0.5, 'costClear' => true, 'qualitySettled' => true], $result['headline']);
        self::assertSame([
            ['name' => 'a', 'model' => 'model-a', 'weight' => 3, 'cards' => 5, 'finishedCards' => 5, 'runs' => 5, 'costUsd' => 10.0],
            ['name' => 'b', 'model' => 'model-b', 'weight' => 1, 'cards' => 5, 'finishedCards' => 5, 'runs' => 5, 'costUsd' => 5.0],
        ], $result['variants']);

        self::assertSame(['cost', 'merge-rate'], array_column($result['metrics'], 'key'));
        self::assertSame([
            'key' => 'cost',
            'valueType' => 'money',
            'clear' => true,
            'byVariant' => [
                'a' => ['point' => 2.0, 'low' => 2.0, 'high' => 2.0],
                'b' => ['point' => 1.0, 'low' => 1.0, 'high' => 1.0],
            ],
            'parts' => [],
        ], $result['metrics'][0]);
        self::assertSame('ratio', $result['metrics'][1]['valueType']);

        self::assertSame(10, $result['includedCards']);
        self::assertSame(1, $result['leftOutCards']);
        self::assertSame([1, 1, 11, false], [$result['page'], $result['totalPages'], $result['total'], $result['hasMore']]);
        self::assertSame([
            'cardId' => (string) $switched->id,
            'number' => 11,
            'title' => 'Card 11',
            'variant' => 'b',
            'column' => 'Done',
            'runs' => 1,
            'fixRounds' => 0,
            'costUsd' => null,
            'leftOut' => ['switched'],
        ], $result['cards'][0]);
        self::assertSame(2.0, $this->cardOf($result['cards'], 1)['costUsd']);
    }

    public function test_the_metric_parts_take_the_value_type_of_their_metric(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-parts@example.com'));
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['fix-rounds'];
        $em->persist($definition);
        $card = $this->experimentCard($em, $project, 1, merged: true);
        $this->cardEvent($em, $card, CardEventKind::FixRequested, ['reason' => 'conflict', 'pullRequest' => 1], '-90 minutes');
        $this->experimentRun($em, $project, $card, 'a');
        $this->actAsMcpTokenBoundTo($project);

        $metric = $this->tool()('model-test')['metrics'][0];

        self::assertSame('fix-rounds', $metric['key']);
        self::assertFalse($metric['clear']);
        self::assertSame([[
            'key' => 'conflict',
            'valueType' => 'count',
            'clear' => false,
            'byVariant' => ['a' => ['point' => 1.0, 'low' => 1.0, 'high' => 1.0]],
            'parts' => [],
        ]], $metric['parts']);
    }

    public function test_it_pages_and_filters_the_cards(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-pages@example.com'));
        $count = ShowExperimentHandler::PER_PAGE + 1;
        for ($i = 1; $i <= $count; ++$i) {
            $this->experimentRun($em, $project, $this->experimentCard($em, $project, $i), 0 === $i % 2 ? 'b' : 'a');
        }
        $switched = $this->experimentCard($em, $project, $count + 1);
        $this->experimentRun($em, $project, $switched, 'b', switchedFrom: 'a');
        $this->actAsMcpTokenBoundTo($project);

        $first = $this->tool()('model-test');
        $second = $this->tool()('model-test', page: 2);

        self::assertCount(ShowExperimentHandler::PER_PAGE, $first['cards']);
        self::assertSame([1, 2, $count + 1, true], [$first['page'], $first['totalPages'], $first['total'], $first['hasMore']]);
        self::assertCount(2, $second['cards']);
        self::assertSame([2, false], [$second['page'], $second['hasMore']]);

        $variantB = $this->tool()('model-test', variant: 'b');
        self::assertSame(intdiv($count, 2), $variantB['total']);
        self::assertSame(['b'], array_values(array_unique(array_column($variantB['cards'], 'variant'))));

        $leftOut = $this->tool()('model-test', leftOutOnly: true);
        self::assertSame([(string) $switched->id], array_column($leftOut['cards'], 'cardId'));
    }

    public function test_an_unknown_experiment_is_refused(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-unknown@example.com'));
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No experiment named "no-such-test" exists in this project.');

        $this->tool()('no-such-test');
    }

    public function test_an_experiment_of_another_project_is_unknown(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-mine@example.com'));
        $other = $this->boardProject($em, $this->user($em, 'experiment-get-other@example.com'));
        $this->experimentRun($em, $other, $this->experimentCard($em, $other, 1), 'a');
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No experiment named "model-test" exists in this project.');

        $this->tool()('model-test');
    }

    public function test_it_refuses_an_unbound_token(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-get-unbound@example.com'));
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);

        $this->tool()('model-test');
    }

    /**
     * @param list<array{cardId: string, number: ?int, costUsd: ?float}> $cards
     *
     * @return array{cardId: string, number: ?int, costUsd: ?float}
     */
    private function cardOf(array $cards, int $number): array
    {
        foreach ($cards as $card) {
            if ($number === $card['number']) {
                return $card;
            }
        }

        self::fail(\sprintf('No card %d.', $number));
    }

    private function tool(): ExperimentGetTool
    {
        $tool = self::getContainer()->get(ExperimentGetTool::class);
        self::assertInstanceOf(ExperimentGetTool::class, $tool);

        return $tool;
    }
}
