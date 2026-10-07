<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\BridgeHostSampleRepository;
use Psr\Clock\ClockInterface;

/**
 * One page of the host samples of the bridge of a run, from the start of the
 * run to its end, or to now while it runs. An out-of-range page or page size
 * is clamped, not refused.
 */
final readonly class ListBridgeHostSamplesHandler
{
    public const int PER_PAGE = 100;

    public const int MAX_PER_PAGE = 500;

    public function __construct(
        private BridgeHostSampleRepository $bridgeHostSamples,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ListBridgeHostSamplesCommand $command): ListBridgeHostSamplesView
    {
        $perPage = min(self::MAX_PER_PAGE, max(1, $command->perPage));
        // A page near PHP_INT_MAX overflows the offset.
        $page = min(max(1, $command->page), intdiv(\PHP_INT_MAX, $perPage));
        $run = $command->run;

        if (null === $run->bridgeId) {
            return new ListBridgeHostSamplesView([], $page, $perPage, 0, 'The run has no bridge, so it has no host samples.');
        }
        if (null === $run->startedAt) {
            return new ListBridgeHostSamplesView([], $page, $perPage, 0, 'The run has no start, so it has no window of host samples.');
        }

        $ownerId = $run->project->owner->id ?? throw new \LogicException('The project owner has no id.');
        $to = $run->endedAt ?? $this->clock->now();
        $total = $this->bridgeHostSamples->countForBridgeBetween($ownerId, $run->bridgeId, $run->startedAt, $to);
        $samples = 0 === $total ? [] : $this->bridgeHostSamples->findForBridgeBetween(
            $ownerId,
            $run->bridgeId,
            $run->startedAt,
            $to,
            $perPage,
            ($page - 1) * $perPage,
        );

        return new ListBridgeHostSamplesView($samples, $page, $perPage, $total);
    }
}
