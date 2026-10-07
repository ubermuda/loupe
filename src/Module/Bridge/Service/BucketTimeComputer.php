<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunToolCallRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * Sorts the main-session tool calls of a run into the buckets of the project
 * rules, and stores the time of each bucket. The first rule that matches any
 * signature of a call takes the whole call, and a call no rule takes goes to
 * the fallback bucket. Calls that overlap count once in their bucket.
 */
final readonly class BucketTimeComputer
{
    /** @param iterable<BucketRuleSourceInterface> $sources */
    public function __construct(
        #[AutowireIterator('app.bucket_rule_source')]
        private iterable $sources,
        private WorkerRunToolCallRepository $workerRunToolCalls,
        private WorkerRunBucketTimeRepository $workerRunBucketTimes,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Replaces the bucket rows of each run. A run with no tool call keeps no
     * row, so its time stays unknown.
     *
     * @param list<Uuid> $runIds
     */
    public function recompute(Project $project, array $runIds): void
    {
        $rules = [];
        foreach ($this->sources as $source) {
            array_push($rules, ...$source->rulesFor($project));
        }

        $this->em->wrapInTransaction(function () use ($runIds, $rules): void {
            foreach ($runIds as $runId) {
                $this->workerRunBucketTimes->replaceForRun($runId, $this->timesOf($this->workerRunToolCalls->findBucketInputsOfRun($runId), $rules));
            }
        });
    }

    /**
     * @param list<array{signatures: list<string>, startedAt: \DateTimeImmutable, durationMs: ?int, inSubagent: bool}> $calls
     * @param list<BucketRule>                                                                                         $rules
     *
     * @return array<int|string, int> bucket name => milliseconds
     */
    public function timesOf(array $calls, array $rules): array
    {
        /** @var array<string, list<array{int, int}>> $intervals */
        $intervals = [];
        foreach ($calls as $call) {
            if ($call['inSubagent'] || null === $call['durationMs']) {
                continue;
            }
            $start = (int) $call['startedAt']->format('U') * 1000 + intdiv((int) $call['startedAt']->format('u'), 1000);
            $intervals[self::bucketOf($call['signatures'], $rules)][] = [$start, $start + max(0, $call['durationMs'])];
        }

        $times = array_map(self::unionLength(...), $intervals);
        // A run with calls and no countable time is known, with no time in any bucket.
        if ([] === $times && [] !== $calls) {
            $times[BucketRule::FALLBACK] = 0;
        }

        return $times;
    }

    /**
     * @param list<string>     $signatures
     * @param list<BucketRule> $rules
     */
    private static function bucketOf(array $signatures, array $rules): string
    {
        foreach ($rules as $rule) {
            foreach ($signatures as $signature) {
                if ($rule->matches($signature)) {
                    return $rule->bucket;
                }
            }
        }

        return BucketRule::FALLBACK;
    }

    /** @param list<array{int, int}> $intervals */
    private static function unionLength(array $intervals): int
    {
        sort($intervals);
        $total = 0;
        $end = null;
        foreach ($intervals as [$from, $to]) {
            if (null === $end || $from > $end) {
                $total += $to - $from;
                $end = $to;
            } elseif ($to > $end) {
                $total += $to - $end;
                $end = $to;
            }
        }

        return $total;
    }
}
