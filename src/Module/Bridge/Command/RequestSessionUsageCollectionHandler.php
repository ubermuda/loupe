<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeCommandPayload;
use App\Module\Bridge\Service\BridgeCommandTtl;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Outbox\OutboxWriter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Asks the bridges that can hold the transcript of an ended interactive run to
 * report its usage. A launched run asks its own bridge. Any other run asks
 * every bridge of the owner that follows the project, because Loupe does not
 * know which machine ran the session.
 */
final readonly class RequestSessionUsageCollectionHandler
{
    public function __construct(
        private BridgeRepository $bridges,
        private BridgeCommandRepository $bridgeCommands,
        private BridgeCommandTtl $ttl,
        private OutboxWriter $outbox,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
        private LoggerInterface $logger,
    ) {
    }

    /** @return list<BridgeCommand> the commands written, none when the run needs no collection */
    public function __invoke(RequestSessionUsageCollectionCommand $command): array
    {
        $run = $command->run;
        $context = ['projectId' => (string) $run->project->id, 'runId' => (string) $run->id];
        $skipped = $this->skipReasonOf($run);
        if (null !== $skipped) {
            $this->logger->info('bridge.session_usage_collection_skipped', $context + ['reason' => $skipped]);

            return [];
        }

        // Read before the transaction, so a failed read leaves the entity manager open.
        $bridges = $this->capableBridges($run);
        if ([] === $bridges) {
            $this->logger->info('bridge.session_usage_collection_skipped', $context + ['reason' => 'no_bridge']);

            return [];
        }

        try {
            /** @var list<BridgeCommand> $commands */
            $commands = $this->em->wrapInTransaction(function () use ($run, $bridges): array {
                $ownerId = (string) ($run->project->owner->id ?? throw new \LogicException('A project owner always has an id.'));
                // The lock a command request takes, in a fixed order so two callers cannot deadlock.
                foreach ($bridges as $bridge) {
                    $this->bridges->lockForWrite($ownerId, $bridge->id);
                }
                if ($this->bridgeCommands->hasPendingCollectionForRun($run)) {
                    return [];
                }

                $now = $this->clock->now();
                $commands = [];
                foreach ($bridges as $bridge) {
                    $commands[] = $bridgeCommand = new BridgeCommand(
                        owner: $run->project->owner,
                        bridgeId: $bridge->id,
                        project: $run->project,
                        workerRun: $run,
                        kind: BridgeCommandKind::CollectSessionUsage,
                        requestedBy: null,
                        requestedAt: $now,
                        expiresAt: $this->ttl->expiresAt($now),
                    );
                    $this->em->persist($bridgeCommand);
                }
                // The payload names the command, so the rows need their ids first.
                $this->em->flush();
                foreach ($commands as $bridgeCommand) {
                    $this->outbox->write($run->project, BridgeEventType::COMMAND, BridgeCommandPayload::of($bridgeCommand));
                }
                $this->em->flush();

                return $commands;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), BridgeCommand::PENDING_RUN_INDEX)) {
                throw $e;
            }

            $commands = [];
        }

        if ([] === $commands) {
            $this->logger->info('bridge.session_usage_collection_skipped', $context + ['reason' => 'pending']);

            return [];
        }

        // After the commit, so a rollback leaves no record.
        foreach ($commands as $bridgeCommand) {
            $this->auditor->record(
                'bridge.command_requested',
                AuditOutcome::Success,
                $context + [
                    'commandId' => (string) $bridgeCommand->id,
                    'kind' => $bridgeCommand->kind->value,
                    'bridgeId' => (string) $bridgeCommand->bridgeId,
                ],
                new AuditSubject('bridge_command', (string) $bridgeCommand->id),
            );
        }

        return $commands;
    }

    private function skipReasonOf(WorkerRun $run): ?string
    {
        return match (true) {
            WorkerRunKind::Interactive !== $run->kind => 'not_interactive',
            WorkerRunState::Running === $run->state, null === $run->endedAt => 'not_ended',
            null === $run->sessionId => 'no_session',
            null === $run->startedAt => 'not_started',
            null !== $run->usageSource => 'has_usage',
            default => null,
        };
    }

    /** @return list<Bridge> sorted by id */
    private function capableBridges(WorkerRun $run): array
    {
        if (null !== $run->bridgeId) {
            $own = $this->bridges->findOneByOwnerAndId($run->project->owner, $run->bridgeId);
            $bridges = null === $own ? [] : [$own];
        } else {
            $bridges = $this->bridges->findFollowingProject($run->project);
        }

        $bridges = array_values(array_filter($bridges, static fn (Bridge $bridge): bool => $bridge->takesSessionUsage()));
        usort($bridges, static fn (Bridge $a, Bridge $b): int => $a->id->toRfc4122() <=> $b->id->toRfc4122());

        return $bridges;
    }
}
