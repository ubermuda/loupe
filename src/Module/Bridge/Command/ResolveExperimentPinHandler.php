<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Pins the candidate on the first call for a card, keeps a pin the rule still
 * offers, and moves a pin the rule dropped to the candidate. Every call
 * refreshes the pin, so the retention sweep keeps the pins of active cards.
 */
final readonly class ResolveExperimentPinHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private ExperimentPinRepository $experimentPins,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ResolveExperimentPinCommand $command): ResolveExperimentPinResult
    {
        return $this->em->wrapInTransaction(function () use ($command): ResolveExperimentPinResult {
            // The project lock orders this write after a project deletion, so
            // the insert never meets a project row that is going away.
            $project = $this->lockedProject($command);
            if (null === $project) {
                return new ResolveExperimentPinResult(null);
            }

            $now = $this->clock->now();
            // Two first calls for one card can race. The loser inserts nothing
            // and reads the winner's pin under the lock below.
            $inserted = 1 === $this->em->getConnection()->executeStatement(
                'INSERT INTO bridge_experiment_pins (id, project_id, card_id, experiment, variant, created_at, updated_at)'
                .' VALUES (:id, :project, :card, :experiment, :variant, :now, :now)'
                .' ON CONFLICT (project_id, card_id, experiment) DO NOTHING',
                [
                    'id' => Uuid::v7(),
                    'project' => $project->id,
                    'card' => $command->cardId,
                    'experiment' => $command->experiment,
                    'variant' => $command->candidate,
                    'now' => $now,
                ],
                [
                    'id' => UuidType::NAME,
                    'project' => UuidType::NAME,
                    'card' => UuidType::NAME,
                    'experiment' => Types::STRING,
                    'variant' => Types::STRING,
                    'now' => Types::DATETIME_IMMUTABLE,
                ],
            );

            $pin = $this->experimentPins->findOneLocked($project, $command->cardId, $command->experiment)
                ?? throw new \LogicException('A pin exists once its insert ran.');

            $switchedFrom = null;
            if (!$inserted && !\in_array($pin->variant, $command->variants, true)) {
                $switchedFrom = $pin->variant;
                $pin->variant = $command->candidate;
            }

            $pin->updatedAt = $now;
            $this->em->flush();

            return new ResolveExperimentPinResult($pin->variant, $switchedFrom);
        });
    }

    private function lockedProject(ResolveExperimentPinCommand $command): ?Project
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return null;
        }

        $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

        return $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
    }
}
