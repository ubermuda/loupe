<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Twig;

use App\Module\Bridge\Twig\CardWorkerRunsExtension;
use App\Module\Bridge\View\WorkerRunListItem;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardWorkerRunsExtensionTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_returns_the_cards_latest_runs_in_this_project_only(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'card-runs@example.com');
        $project = $this->project($em, $owner, 'Runs');
        $other = $this->project($em, $owner, 'Other runs');
        $cardId = Uuid::v7();
        for ($minute = 1; $minute <= 7; ++$minute) {
            $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-01-01 11:0'.$minute.':00'), ruleName: 'rule-'.$minute, cardId: $cardId);
        }
        $this->seedRun($em, $project, ruleName: 'another card');
        $this->seedRun($em, $other, ruleName: 'another project', cardId: $cardId);

        $runs = self::getContainer()->get(CardWorkerRunsExtension::class)->cardWorkerRuns($project, (string) $cardId);

        self::assertSame(
            ['rule-7', 'rule-6', 'rule-5', 'rule-4', 'rule-3'],
            array_map(static fn (WorkerRunListItem $item): string => $item->run->ruleName, $runs),
        );
    }

    public function test_a_card_with_no_runs_gets_an_empty_list(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'card-no-runs@example.com'), 'Quiet');

        self::assertSame([], self::getContainer()->get(CardWorkerRunsExtension::class)->cardWorkerRuns($project, (string) Uuid::v7()));
    }
}
