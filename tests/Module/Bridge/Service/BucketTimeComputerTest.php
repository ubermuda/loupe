<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunToolCallRepository;
use App\Module\Bridge\Service\BucketRule;
use App\Module\Bridge\Service\BucketRuleSourceInterface;
use App\Module\Bridge\Service\BucketTimeComputer;
use App\Module\Bridge\ValueObject\WorkerRunToolCallReport;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BucketTimeComputerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string START = '2026-01-01 10:00:00';

    public function test_the_first_rule_that_matches_a_signature_takes_the_whole_call(): void
    {
        $rules = [new BucketRule('Bash(git *)', 'git'), new BucketRule('Bash(*)', 'shell'), new BucketRule('Read', 'reading')];

        $times = $this->computer([])->timesOf([
            $this->call(0, 1000, ['Bash(ls)', 'Bash(git status)']),
            $this->call(2, 500, ['Bash(ls)']),
            $this->call(4, 250, ['Read']),
            $this->call(6, 100, ['Grep']),
        ], $rules);

        self::assertSame(['git' => 1000, 'shell' => 500, 'reading' => 250, 'other' => 100], $times);
    }

    public function test_a_rule_earlier_in_the_order_wins_over_a_later_one_that_also_matches(): void
    {
        $call = $this->call(0, 1000, ['Bash(git status)']);

        self::assertSame(['shell' => 1000], $this->computer([])->timesOf([$call], [new BucketRule('Bash(*)', 'shell'), new BucketRule('Bash(git *)', 'git')]));
        self::assertSame(['git' => 1000], $this->computer([])->timesOf([$call], [new BucketRule('Bash(git *)', 'git'), new BucketRule('Bash(*)', 'shell')]));
    }

    public function test_calls_that_overlap_count_once_in_their_bucket(): void
    {
        $rules = [new BucketRule('Bash(*)', 'shell')];

        $times = $this->computer([])->timesOf([
            $this->call(0, 1000, ['Bash(a)']),
            $this->call(500, 1000, ['Bash(b)']),
            $this->call(300, 100, ['Bash(c)']),
            $this->call(5000, 200, ['Bash(d)']),
            $this->call(1500, 100, ['Read']),
        ], $rules);

        self::assertSame(['shell' => 1700, 'other' => 100], $times);
    }

    public function test_a_call_that_ends_where_the_next_begins_adds_both_lengths(): void
    {
        $times = $this->computer([])->timesOf([$this->call(0, 1000, ['Read']), $this->call(1000, 500, ['Read'])], []);

        self::assertSame(['other' => 1500], $times);
    }

    public function test_the_start_keeps_its_milliseconds(): void
    {
        $times = $this->computer([])->timesOf([
            $this->call(0, 1, ['Read'], '.250000'),
            $this->call(0, 1, ['Read'], '.250999'),
            $this->call(0, 1, ['Read'], '.251000'),
        ], []);

        self::assertSame(['other' => 2], $times);
    }

    public function test_a_subagent_call_and_a_call_with_no_duration_add_nothing(): void
    {
        $times = $this->computer([])->timesOf([
            $this->call(0, 1000, ['Read']),
            $this->call(2000, 9000, ['Read'], inSubagent: true),
            $this->call(20000, null, ['Read']),
        ], []);

        self::assertSame(['other' => 1000], $times);
    }

    public function test_a_run_whose_calls_all_add_nothing_has_no_time_in_the_fallback_bucket(): void
    {
        $computer = $this->computer([]);

        self::assertSame(['other' => 0], $computer->timesOf([$this->call(0, 100, ['Read'], inSubagent: true)], []));
        self::assertSame([], $computer->timesOf([], []));
    }

    public function test_it_replaces_the_rows_of_a_run_and_a_second_run_changes_nothing(): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), $this->user($this->em(), 'bucket-computer-'.uniqid().'@example.com'), 'Bucket computer');
        $run = $this->seedRun($this->em(), $project);
        $this->store($run, [$this->call(0, 1000, ['Bash(git push)']), $this->call(2000, 300, ['Read'])]);
        $this->bucketTimes()->replaceForRun($this->idOf($run), ['stale' => 7, 'other' => 1]);
        $computer = $this->computer([new BucketRule('Bash(git *)', 'git')]);

        $computer->recompute($project, [$this->idOf($run)]);
        $computer->recompute($project, [$this->idOf($run)]);

        self::assertSame(['git' => 1000, 'other' => 300], $this->rowsOf($run));
    }

    public function test_a_run_with_no_tool_call_keeps_no_row(): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), $this->user($this->em(), 'bucket-computer-none-'.uniqid().'@example.com'), 'Bucket none');
        $run = $this->seedRun($this->em(), $project);
        $this->bucketTimes()->replaceForRun($this->idOf($run), ['other' => 5]);

        $this->computer([])->recompute($project, [$this->idOf($run)]);

        self::assertSame([], $this->rowsOf($run));
    }

    public function test_the_rules_of_every_source_apply_in_the_order_of_the_sources(): void
    {
        $first = self::source([new BucketRule('Read', 'first')]);
        $second = self::source([new BucketRule('Read', 'second'), new BucketRule('Grep', 'grep')]);
        $computer = new BucketTimeComputer([$first, $second], $this->toolCalls(), $this->bucketTimes(), $this->em());
        $project = $this->project($this->em(), $this->user($this->em(), 'bucket-computer-sources-'.uniqid().'@example.com'), 'Bucket sources');
        $run = $this->seedRun($this->em(), $project);
        $this->store($run, [$this->call(0, 100, ['Read']), $this->call(1000, 200, ['Grep'])]);

        $computer->recompute($project, [$this->idOf($run)]);

        self::assertSame(['first' => 100, 'grep' => 200], $this->rowsOf($run));
    }

    /** @param list<BucketRule> $rules */
    private function computer(array $rules): BucketTimeComputer
    {
        self::bootKernel();

        return new BucketTimeComputer([self::source($rules)], $this->toolCalls(), $this->bucketTimes(), $this->em());
    }

    /** @param list<BucketRule> $rules */
    private static function source(array $rules): BucketRuleSourceInterface
    {
        return new readonly class($rules) implements BucketRuleSourceInterface {
            /** @param list<BucketRule> $rules */
            public function __construct(
                private array $rules,
            ) {
            }

            #[\Override]
            public function rulesFor(Project $project): array
            {
                return $this->rules;
            }
        };
    }

    /**
     * @param list<string> $signatures
     *
     * @return array{signatures: list<string>, startedAt: \DateTimeImmutable, durationMs: ?int, inSubagent: bool}
     */
    private function call(int $offsetMs, ?int $durationMs, array $signatures, string $fraction = '.000000', bool $inSubagent = false): array
    {
        $start = new \DateTimeImmutable(self::START.$fraction, new \DateTimeZone('UTC'))->modify(\sprintf('+%d milliseconds', $offsetMs));

        return ['signatures' => $signatures, 'startedAt' => $start, 'durationMs' => $durationMs, 'inSubagent' => $inSubagent];
    }

    /** @param list<array{signatures: list<string>, startedAt: \DateTimeImmutable, durationMs: ?int, inSubagent: bool}> $calls */
    private function store(WorkerRun $run, array $calls): void
    {
        $this->toolCalls()->insertNew($run, array_map(static fn (array $call, int $seq): WorkerRunToolCallReport => new WorkerRunToolCallReport(
            $seq + 1,
            'Bash',
            $call['startedAt'],
            $call['durationMs'],
            false,
            $call['inSubagent'],
            null,
            null,
            $call['signatures'],
            null,
        ), $calls, array_keys($calls)));
    }

    /** @return array<string, int> */
    private function rowsOf(WorkerRun $run): array
    {
        /** @var array<string, int> $rows */
        $rows = $this->em()->getConnection()->fetchAllKeyValue('SELECT bucket, ms FROM bridge_worker_run_bucket_times WHERE run_id = :run ORDER BY bucket', ['run' => (string) $run->id]);
        ksort($rows);

        return array_map(intval(...), $rows);
    }

    private function idOf(WorkerRun $run): Uuid
    {
        return $run->id ?? throw new \LogicException('A stored run has an id.');
    }

    private function toolCalls(): WorkerRunToolCallRepository
    {
        $repository = self::getContainer()->get(WorkerRunToolCallRepository::class);
        self::assertInstanceOf(WorkerRunToolCallRepository::class, $repository);

        return $repository;
    }

    private function bucketTimes(): WorkerRunBucketTimeRepository
    {
        $repository = self::getContainer()->get(WorkerRunBucketTimeRepository::class);
        self::assertInstanceOf(WorkerRunBucketTimeRepository::class, $repository);

        return $repository;
    }
}
