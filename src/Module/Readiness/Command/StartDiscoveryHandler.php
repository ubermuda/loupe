<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Readiness\Service\ReadinessChecklist;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\CardTypeCatalog;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Opens a discovery run on a new Backlog card. The app rule then requests the work from a bridge. */
final readonly class StartDiscoveryHandler
{
    private const string CARD_TYPE = 'tooling';

    public const string NO_LIVE_BRIDGE = 'readiness.discovery.error.no_bridge';

    public const string NO_WORKFLOW = 'readiness.discovery.error.no_workflow';

    public const string AUTOMATION_OFF = 'readiness.discovery.error.automation_off';

    public function __construct(
        private ReadinessChecklist $checklist,
        private DiscoveryRunRepository $discoveryRuns,
        private CreateCardHandler $createCard,
        private CardEvaluations $evaluations,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private CardTypeCatalog $catalog,
    ) {
    }

    public function __invoke(StartDiscoveryCommand $command): DiscoveryRun
    {
        $project = $command->project;
        // A run the engine cannot request would stay requested with no work.
        $refusal = $this->checklist->startRefusal($project);
        if (null !== $refusal) {
            $field = match ($refusal) {
                self::NO_WORKFLOW => 'workflow',
                self::AUTOMATION_OFF => 'automation',
                default => 'bridge',
            };
            throw new DomainErrors([$field => $refusal]);
        }

        // The lock makes the check and the new card one step, so two starts cannot both pass. A refusal leaves the closure as a value, because a throw closes the EntityManager.
        $run = $this->em->wrapInTransaction(function () use ($command, $project): DiscoveryRun|DiscoveryRunning {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            $latest = $this->discoveryRuns->latestForProject($project);
            if (DiscoveryRunState::Requested === $latest?->state) {
                return new DiscoveryRunning($latest->card->number);
            }

            $types = $this->catalog->forProject($project->requireId());
            $card = ($this->createCard)(new CreateCardCommand(
                project: $project,
                title: 'Discover what '.$project->name.' needs for agents', // @translation-check-ignore
                body: 'This card tracks a read-only discovery run. A worker reads the repository and the workflow, and writes a readiness report. The app moves this card, so leave it where it is.', // @translation-check-ignore
                type: $types->has(self::CARD_TYPE) ? self::CARD_TYPE : $types->defaultKey,
                reporter: $command->reporter,
            ));
            $run = new DiscoveryRun($project, $card);
            $this->em->persist($run);
            $this->em->flush();

            return $run;
        });
        if ($run instanceof DiscoveryRunning) {
            throw $run;
        }

        $cardId = $run->card->id ?? throw new \LogicException('A flushed card has an id.');
        $this->auditor->record(
            'readiness.discovery_started',
            AuditOutcome::Success,
            [
                'discoveryRunId' => (string) $run->id,
                'projectId' => (string) $project->id,
                'cardId' => (string) $cardId,
                'reporter' => $command->reporter->value,
            ],
            new AuditSubject('discovery_run', (string) $run->id),
        );
        // No listener evaluates a new card, and the app rule fires only when the engine evaluates it.
        $this->evaluations->forCards([$cardId]);

        return $run;
    }
}
