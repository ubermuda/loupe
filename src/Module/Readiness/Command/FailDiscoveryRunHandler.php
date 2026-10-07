<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Fails the latest discovery run of the card while it is requested, and cancels the discovery request the run opened. */
final readonly class FailDiscoveryRunHandler
{
    public const string RULE_ID = 'discovery';

    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private WorkRequestRepository $workRequests,
        private WithdrawWorkRequestHandler $withdrawWorkRequest,
        private CardEvaluations $evaluations,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    /** Answers whether the run failed now. */
    public function __invoke(FailDiscoveryRunCommand $command): bool
    {
        $run = $this->discoveryRuns->latestForCard($command->cardId);
        if (null === $run || !$run->fail($command->reason, $this->clock->now())) {
            return false;
        }
        $this->em->flush();

        // The engine cancels a live request only when its card leaves the slot. A bridge that drops its claim lets the request open again.
        foreach ($this->workRequests->findLiveForCard($command->cardId) as $request) {
            if (self::RULE_ID === $request->ruleId) {
                ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($request->id ?? throw new \LogicException('A persisted work request has an id.'), WorkRequestState::Cancelled));
            }
        }

        $this->auditor->record(
            'readiness.discovery_failed',
            AuditOutcome::Success,
            [
                'discoveryRunId' => (string) $run->id,
                'projectId' => (string) $run->project->id,
                'cardId' => $command->cardId->toRfc4122(),
                'reason' => $run->failureReason,
            ],
            new AuditSubject('discovery_run', (string) $run->id),
        );
        // The rule records its falling edge, so a later requested run fires it again.
        if ($this->evaluations->isOn()) {
            $this->evaluations->forCards([$command->cardId]);
        }

        return true;
    }
}
