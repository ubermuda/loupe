<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;

/** Builds the controls of a list of worker runs in a fixed number of queries, plus one column lookup per card that can resume. */
final readonly class WorkerRunControls
{
    /** The first bridge release that reads commands. */
    public const string COMMANDS_SINCE_VERSION = '1.5.0';

    public function __construct(
        private BridgeCommandRepository $bridgeCommands,
        private BridgeRepository $bridges,
        private BridgeLiveness $liveness,
    ) {
    }

    /**
     * @param list<WorkerRun> $runs
     *
     * @return array<string, WorkerRunControl> keyed by the RFC 4122 run id; a run with nothing to show has no entry
     */
    public function forRuns(Project $project, array $runs): array
    {
        $bridgeIds = [];
        $controllable = [];
        foreach ($runs as $run) {
            if (null !== $run->bridgeId && WorkerRunKind::Interactive !== $run->kind) {
                $bridgeIds[$run->bridgeId->toRfc4122()] = $run->bridgeId;
                $controllable[] = $run;
            }
        }
        if ([] === $controllable) {
            return [];
        }

        $owner = $project->owner;
        $bridges = [];
        foreach ($this->bridges->findByOwnerAndIds($owner, array_values($bridgeIds)) as $bridge) {
            $bridges[$bridge->id->toRfc4122()] = $bridge;
        }
        $statuses = $this->liveness->forOwner($owner, array_values($bridgeIds));
        $latest = $this->bridgeCommands->findLatestForRuns($controllable);

        $controls = [];
        foreach ($controllable as $run) {
            $bridgeKey = ($run->bridgeId ?? throw new \LogicException('A controllable run has a bridge.'))->toRfc4122();
            $control = $this->controlOf($run, $latest[(string) $run->id] ?? null, $bridges[$bridgeKey] ?? null, $statuses[$bridgeKey]->quiet ?? true);
            if (null !== $control) {
                $controls[(string) $run->id] = $control;
            }
        }

        return $controls;
    }

    private function controlOf(WorkerRun $run, ?BridgeCommand $latest, ?Bridge $bridge, bool $quiet): ?WorkerRunControl
    {
        if (BridgeCommandState::Pending === $latest?->state) {
            $label = match ($latest->kind) {
                BridgeCommandKind::ResumeRun => 'bridge.worker_runs.control.resume_requested',
                BridgeCommandKind::StopRun => 'bridge.worker_runs.control.stop_requested',
                BridgeCommandKind::RerunCommand => 'bridge.worker_runs.control.rerun_requested',
            };

            return new WorkerRunControl(WorkerRunAction::Cancel, label: $quiet ? $label.'_offline' : $label, pendingCommand: $latest);
        }

        // The request handler refuses a bridge the owner does not hold, and no update fixes that.
        $action = null === $bridge ? null : match (true) {
            $run->state->isStoppable() => WorkerRunAction::Stop,
            WorkerRunKind::Command === $run->kind && $run->state->isRerunnable() => WorkerRunAction::Rerun,
            $run->state->isResumable() && null !== $run->sessionId => WorkerRunAction::Resume,
            default => null,
        };
        $notice = $this->noticeOf($run, $latest, $bridge);
        if (null === $action && null === $notice) {
            return null;
        }

        $disabledReason = null;
        $disabledParameters = [];
        if (null !== $action && null !== $bridge && !$bridge->takesCommands()) {
            $disabledReason = 'bridge.worker_runs.control.bridge_outdated';
            $disabledParameters = ['%version%' => self::COMMANDS_SINCE_VERSION];
        }
        if (null === $disabledReason && WorkerRunAction::Rerun === $action && null !== $bridge && !$bridge->takesReruns()) {
            $disabledReason = 'bridge.worker_runs.control.rerun_outdated';
        }

        return new WorkerRunControl(
            $action,
            $disabledReason,
            $disabledParameters,
            $notice[0] ?? null,
            $notice[1] ?? [],
            $notice[2] ?? false,
        );
    }

    /** @return array{string, array<string, string>, bool}|null the label, its parameters, and whether it warns */
    private function noticeOf(WorkerRun $run, ?BridgeCommand $latest, ?Bridge $bridge): ?array
    {
        if (null !== $latest && $this->stillApplies($latest->kind, $run->state)) {
            if (BridgeCommandState::Expired === $latest->state) {
                $key = match ($latest->kind) {
                    BridgeCommandKind::ResumeRun => 'bridge.worker_runs.control.resume_expired',
                    BridgeCommandKind::StopRun => 'bridge.worker_runs.control.stop_expired',
                    BridgeCommandKind::RerunCommand => 'bridge.worker_runs.control.rerun_expired',
                };

                return [$key, [], true];
            }
            if (BridgeCommandState::Refused === $latest->state) {
                return null === $latest->reason
                    ? ['bridge.worker_runs.control.refused_no_reason', [], true]
                    : ['bridge.worker_runs.control.refused', ['%reason%' => $latest->reason], true];
            }
        }

        if (WorkerRunState::Queued === $run->state && true === $bridge?->pausedReported) {
            return ['bridge.worker_runs.control.waiting_paused', [], false];
        }

        return null;
    }

    private function stillApplies(BridgeCommandKind $kind, WorkerRunState $state): bool
    {
        return match ($kind) {
            BridgeCommandKind::ResumeRun => $state->isResumable(),
            BridgeCommandKind::StopRun => $state->isStoppable(),
            BridgeCommandKind::RerunCommand => $state->isRerunnable(),
        };
    }
}
