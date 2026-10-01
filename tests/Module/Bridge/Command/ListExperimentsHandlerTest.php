<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ListExperimentsCommand;
use App\Module\Bridge\Command\ListExperimentsHandler;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Experiment\ExperimentSummary;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ListExperimentsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = $this->em();
    }

    public function test_it_lists_the_experiments_of_the_project_with_the_latest_run_first(): void
    {
        $owner = $this->user($this->em, 'experiments-'.uniqid().'@example.com');
        $project = $this->project($this->em, $owner, 'Experiments');
        $other = $this->project($this->em, $owner, 'Other experiments');

        $card = Uuid::v7();
        $this->experimentRun($project, $card, 'older-test', '2026-09-01 10:00:00');
        $this->experimentRun($project, $card, 'older-test', '2026-09-02 10:00:00');
        $this->experimentRun($project, Uuid::v7(), 'older-test', '2026-09-01 12:00:00');
        // A pin on a card that ran counts once, and a pin alone adds a card.
        $this->em->persist(new ExperimentPin($project, $card, 'older-test', 'a'));
        $this->em->persist(new ExperimentPin($project, Uuid::v7(), 'older-test', 'a'));
        $this->experimentRun($project, Uuid::v7(), 'newer-test', '2026-09-03 10:00:00');
        $this->em->persist(new ExperimentPin($project, Uuid::v7(), 'pinned-test', 'a'));
        $this->experimentRun($project, Uuid::v7(), null, '2026-09-04 10:00:00');
        $this->experimentRun($other, Uuid::v7(), 'foreign-test', '2026-09-05 10:00:00');
        $this->em->flush();

        $handler = new ListExperimentsHandler(
            self::getContainer()->get(WorkerRunRepository::class),
            self::getContainer()->get(ExperimentPinRepository::class),
        );
        $view = $handler(new ListExperimentsCommand($project));

        self::assertEquals([
            new ExperimentSummary('newer-test', 1, new \DateTimeImmutable('2026-09-03 10:00:00')),
            new ExperimentSummary('older-test', 3, new \DateTimeImmutable('2026-09-02 10:00:00')),
            new ExperimentSummary('pinned-test', 1, null),
        ], $view->experiments);
    }

    private function experimentRun(Project $project, Uuid $cardId, ?string $experiment, string $receivedAt): WorkerRun
    {
        $run = $this->seedRun($this->em, $project, receivedAt: new \DateTimeImmutable($receivedAt), cardId: $cardId);
        $run->experiment = $experiment;
        $run->variant = null === $experiment ? null : 'a';
        $this->em->flush();

        return $run;
    }
}
