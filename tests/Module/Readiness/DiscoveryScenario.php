<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Tests\Module\Workflow\WorkflowProjects;

/** Projects, discovery cards and discovery runs for the database tests of discovery. */
trait DiscoveryScenario
{
    use WorkflowProjects;

    private int $cardNumber = 0;

    private function discoveryCard(Project $project, string $column = 'backlog'): Card
    {
        $card = new Card($project, $this->column($project, $column), 'Discovery', '', ++$this->cardNumber);
        $card->type = 'tooling';
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    private function discoveryRun(Card $card, DiscoveryRunState $state = DiscoveryRunState::Requested, string $createdAt = '2026-10-02 12:00:00'): DiscoveryRun
    {
        $run = new DiscoveryRun($card->project, $card, new \DateTimeImmutable($createdAt));
        $run->state = $state;
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }
}
