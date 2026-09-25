<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use App\Module\Bridge\Service\WorkerRunUsageRecorder;
use App\Module\Bridge\ValueObject\WorkerRunModelUsage;
use App\Module\Bridge\ValueObject\WorkerRunUsageReport;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkerRunUsageRecorderTest extends KernelTestCase
{
    use BridgeScenario;

    /**
     * @return iterable<string, array{WorkerRunUsageSource|null, WorkerRunUsageSource, bool}>
     */
    public static function merges(): iterable
    {
        yield 'reported fills unknown usage' => [null, WorkerRunUsageSource::Reported, true];
        yield 'estimated fills unknown usage' => [null, WorkerRunUsageSource::Estimated, true];
        yield 'reported replaces estimated' => [WorkerRunUsageSource::Estimated, WorkerRunUsageSource::Reported, true];
        yield 'estimated never replaces reported' => [WorkerRunUsageSource::Reported, WorkerRunUsageSource::Estimated, false];
        yield 'reported never replaces reported' => [WorkerRunUsageSource::Reported, WorkerRunUsageSource::Reported, false];
        yield 'estimated never replaces estimated' => [WorkerRunUsageSource::Estimated, WorkerRunUsageSource::Estimated, false];
    }

    #[DataProvider('merges')]
    public function test_the_better_source_wins(?WorkerRunUsageSource $current, WorkerRunUsageSource $incoming, bool $replaces): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'usage-merge-'.($current?->value ?? 'none').'-'.$incoming->value.'@example.com'), 'Usage Merge');
        $run = $this->seedRun($em, $project);
        if (null !== $current) {
            $this->recorder()->record($run, self::report($current, 'claude-old', 1));
            $em->flush();
        }

        $changed = $this->recorder()->record($run, self::report($incoming, 'claude-new', 2));
        $em->flush();

        self::assertSame($replaces, $changed);
        self::assertSame($replaces ? $incoming : $current, $run->usageSource);
        $expected = $replaces ? [['claude-new', '2']] : [['claude-old', '1']];
        self::assertSame($expected, $this->rowsOf($run));
    }

    /** Reported counts carry the same models as the estimate they replace, and the unique index holds. */
    public function test_reported_replaces_an_estimate_of_the_same_model(): void
    {
        self::bootKernel();
        $em = $this->em();
        $run = $this->seedRun($em, $this->project($em, $this->user($em, 'usage-same-model@example.com'), 'Usage Same Model'));
        $this->recorder()->record($run, self::report(WorkerRunUsageSource::Estimated, 'claude-opus', 5));
        $em->flush();

        self::assertTrue($this->recorder()->record($run, self::report(WorkerRunUsageSource::Reported, 'claude-opus', 7)));
        $em->flush();

        self::assertSame([['claude-opus', '7']], $this->rowsOf($run));
    }

    public function test_no_models_records_a_run_that_spent_nothing(): void
    {
        self::bootKernel();
        $em = $this->em();
        $run = $this->seedRun($em, $this->project($em, $this->user($em, 'usage-zero@example.com'), 'Usage Zero'));

        self::assertTrue($this->recorder()->record($run, new WorkerRunUsageReport(WorkerRunUsageSource::Reported, [])));
        $em->flush();

        self::assertSame(WorkerRunUsageSource::Reported, $run->usageSource);
        self::assertSame([], $this->rowsOf($run));
    }

    public function test_a_row_copies_the_card_and_the_rule_of_its_run(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'usage-copy@example.com'), 'Usage Copy');
        $run = $this->seedRun($em, $project, ruleName: 'review');

        $this->recorder()->record($run, new WorkerRunUsageReport(WorkerRunUsageSource::Estimated, [
            new WorkerRunModelUsage('claude-haiku', 10, 20, 30, 40, null),
        ]));
        $em->flush();

        $row = $em->getConnection()->fetchAssociative('SELECT * FROM bridge_worker_run_usage WHERE run_id = ?', [(string) $run->id]);
        self::assertIsArray($row);
        self::assertSame((string) $project->id, $row['project_id']);
        self::assertSame((string) $run->cardId, $row['card_id']);
        self::assertSame('review', $row['rule_name']);
        self::assertSame([10, 20, 30, 40], [$row['input_tokens'], $row['output_tokens'], $row['cache_read_tokens'], $row['cache_write_tokens']]);
        self::assertNull($row['cost_usd']);
    }

    private static function report(WorkerRunUsageSource $source, string $model, int $inputTokens): WorkerRunUsageReport
    {
        return new WorkerRunUsageReport($source, [new WorkerRunModelUsage($model, $inputTokens, 0, 0, 0, '0.100000')]);
    }

    /** @return list<array{string, string}> */
    private function rowsOf(WorkerRun $run): array
    {
        /** @var list<array{model: string, input_tokens: int|string}> $rows */
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT model, input_tokens FROM bridge_worker_run_usage WHERE run_id = ? ORDER BY model',
            [(string) $run->id],
        );

        return array_map(static fn (array $row): array => [$row['model'], (string) $row['input_tokens']], $rows);
    }

    private function recorder(): WorkerRunUsageRecorder
    {
        $usage = self::getContainer()->get(WorkerRunUsageRepository::class);
        self::assertInstanceOf(WorkerRunUsageRepository::class, $usage);

        return new WorkerRunUsageRecorder($usage, $this->em());
    }
}
