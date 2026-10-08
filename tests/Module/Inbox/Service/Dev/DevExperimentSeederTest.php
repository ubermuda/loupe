<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service\Dev;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\ExperimentDefinitionRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Inbox\Service\Dev\DevExperimentSeeder;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevExperimentSeederTest extends KernelTestCase
{
    use BoardColumnFixtures;
    use BridgeScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_finished_card_completes_after_its_last_run_and_an_open_card_does_not(): void
    {
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'dev-experiment-'.uniqid().'@example.com'), 'Experiment');
        $this->seedColumns($project);
        $em->flush();

        self::assertTrue($this->seeder()->seed($project));

        $em->clear();
        $cards = $this->service(CardRepository::class)->findBy(['project' => $project->id]);
        $finished = array_values(array_filter($cards, static fn (Card $card): bool => $card->column->terminal));
        $open = array_values(array_filter($cards, static fn (Card $card): bool => !$card->column->terminal));
        self::assertCount(12, $finished);
        self::assertNotEmpty($open);

        $weeks = [];
        foreach ($finished as $card) {
            $ends = array_map(
                static fn (WorkerRun $run): \DateTimeImmutable => $run->endedAt ?? throw new \LogicException('A seeded run has ended.'),
                $this->service(WorkerRunRepository::class)->findBy(['subjectId' => $card->id]),
            );
            $completedAt = ([] === $ends ? self::fail($card->title.' has no run.') : max($ends))->modify('+2 hours');
            self::assertSame($completedAt->format('Y-m-d H:i:s'), $card->completedAt?->format('Y-m-d H:i:s'), $card->title);
            $weeks[$completedAt->format('o-W')] = true;
        }
        self::assertGreaterThan(1, \count($weeks));

        foreach ($open as $card) {
            self::assertNull($card->completedAt, $card->title);
        }
    }

    private function seeder(): DevExperimentSeeder
    {
        return new DevExperimentSeeder(
            $this->em(),
            $this->service(BoardColumnRepository::class),
            $this->service(CardRepository::class),
            $this->service(CardEventRepository::class),
            $this->service(ExperimentDefinitionRepository::class),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
