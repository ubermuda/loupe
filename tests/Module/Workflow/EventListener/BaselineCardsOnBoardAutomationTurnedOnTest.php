<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\EventListener;

use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BaselineCardsOnBoardAutomationTurnedOnTest extends KernelTestCase
{
    use ActionScenario;

    public function test_turning_the_automation_on_marks_every_card_of_the_project(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('automation-on');
        $cards = [$this->card($project, 'backlog'), $this->card($project, 'next'), $this->card($project, 'done')];
        $other = $this->card($this->workflowProject('automation-other'), 'next');
        $this->save($project, false);
        self::assertSame([], $this->markedCardIds());

        $this->save($project, true);

        $ids = array_map(static fn ($card): string => (string) $card->id, $cards);
        sort($ids);
        self::assertSame($ids, $this->markedCardIds());
        self::assertNotContains((string) $other->id, $this->markedCardIds());
    }

    public function test_a_save_that_keeps_the_automation_on_marks_nothing(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('automation-kept');
        $this->card($project, 'next');

        $this->save($project, true);
        $this->save($project, true);

        self::assertSame([], $this->markedCardIds());
    }

    private function save(Project $project, bool $enabled): void
    {
        $settings = $this->service(BoardAutomation::class)->settingsOf($project);
        $this->service(SaveBoardAutomationSettingsHandler::class)(new SaveBoardAutomationSettingsCommand(
            $project,
            $enabled,
            $settings->mergeStrategy,
            $settings->fixStrategy,
            $settings->loopLimit,
            $settings->commentOnFixQueued,
            $settings->commentOnStaleApproval,
            $settings->syncBehind,
            $settings->mergePullRequests,
            $settings->changeBase,
        ));
    }

    /** @return list<string> */
    private function markedCardIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->em()->getConnection()->fetchFirstColumn('SELECT card_id FROM workflow_pending_baselines ORDER BY card_id');

        return $ids;
    }
}
