<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ListBridgeHostSamplesCommand;
use App\Module\Bridge\Command\ListBridgeHostSamplesHandler;
use App\Module\Bridge\ValueObject\BridgeHostSampleReport;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the host samples of the bridge of one worker run, a page at a time.
 *
 * @phpstan-type SampleRow array{sampledAt: string, cpuPct: list<float>, meanCpuPct: ?float, memUsed: int, memTotal: int, swapUsed: int, batteryPct: ?float, onAc: ?bool}
 */
#[McpTool(name: self::NAME, description: 'List the host samples of the bridge that ran one worker run, oldest first. A host sample is one reading of the machine the bridge runs on. The samples cover the time from the start of the run to its end, or to now while the run is open. They describe the whole machine, so they cover every run on that bridge in that window, not only this run. A bridge sends samples only when the instance flag bridge.host_sampling_enabled is on and the rules.yaml of the bridge does not set collect: false, so a run can have none. Each sample has sampledAt, cpuPct (the use of each core, in percent), meanCpuPct (the mean of cpuPct, rounded to one decimal, or null when cpuPct is empty), memUsed, memTotal and swapUsed (in bytes), batteryPct (null on a machine with no battery) and onAc (whether the machine runs on mains power, or null when it does not say). The response also has runId, bridgeId, and note, which says why the list is empty when the run has no bridge or no start, and is null otherwise. The response is paginated: pass page to walk further, and keep going while hasMore is true. perPage defaults to 100, with a maximum of 500.')]
final readonly class BridgeHostSamplesTool
{
    public const string NAME = 'bridge_host_samples';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ListBridgeHostSamplesHandler $listSamples,
    ) {
    }

    /**
     * @param string $runId   the id of a worker run of this project, from worker_run_list
     * @param int    $page    the 1-based page to read
     * @param int    $perPage how many samples to return per page
     *
     * @return array{runId: string, bridgeId: ?string, note: ?string, samples: list<SampleRow>, page: int, perPage: int, total: int, hasMore: bool}
     */
    public function __invoke(string $runId, int $page = 1, int $perPage = ListBridgeHostSamplesHandler::PER_PAGE): array
    {
        try {
            $run = $this->subjects->requireRun($runId, McpBoundProjectVoter::WORKER_RUN_READ);
            $view = ($this->listSamples)(new ListBridgeHostSamplesCommand($run, $page, $perPage));

            return [
                'runId' => (string) $run->id,
                'bridgeId' => null === $run->bridgeId ? null : (string) $run->bridgeId,
                'note' => $view->note,
                'samples' => array_map(static fn (BridgeHostSampleReport $sample): array => [
                    'sampledAt' => $sample->sampledAt->format(\DateTimeInterface::ATOM),
                    'cpuPct' => $sample->cpuPct,
                    'meanCpuPct' => [] === $sample->cpuPct ? null : round(array_sum($sample->cpuPct) / \count($sample->cpuPct), 1),
                    'memUsed' => $sample->memUsed,
                    'memTotal' => $sample->memTotal,
                    'swapUsed' => $sample->swapUsed,
                    'batteryPct' => $sample->batteryPct,
                    'onAc' => $sample->onAc,
                ], $view->samples),
                'page' => $view->page,
                'perPage' => $view->perPage,
                'total' => $view->total,
                'hasMore' => $view->page * $view->perPage < $view->total,
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The host samples could not be read. The error has been logged.', previous: $e);
        }
    }
}
