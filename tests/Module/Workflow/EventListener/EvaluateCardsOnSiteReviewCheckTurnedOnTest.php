<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\EventListener;

use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EvaluateCardsOnSiteReviewCheckTurnedOnTest extends KernelTestCase
{
    use ActionScenario;

    public function test_turning_the_check_on_evaluates_the_active_cards_with_an_open_pull_request(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('check-turned-on');
        $withOpen = $this->card($project, 'in-review');
        $this->pullRequest($withOpen);
        $withMerged = $this->card($project, 'in-review');
        $this->pullRequest($withMerged, PullRequestState::Merged);
        $this->card($project, 'in-review');
        $done = $this->card($project, 'done');
        $this->pullRequest($done);
        $this->transport()->reset();

        $this->save($project, siteReviewCheck: true);

        self::assertEquals([new EvaluateCard((string) $withOpen->id)], $this->queued());
    }

    public function test_a_save_that_keeps_the_check_on_evaluates_nothing(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('check-kept-on');
        $card = $this->card($project, 'in-review');
        $this->pullRequest($card);
        $this->save($project, siteReviewCheck: true);
        $this->transport()->reset();

        $this->save($project, siteReviewCheck: true);

        self::assertSame([], $this->queued());
    }

    public function test_a_save_that_leaves_the_check_off_evaluates_nothing(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('check-off');
        $card = $this->card($project, 'in-review');
        $this->pullRequest($card);
        $this->transport()->reset();

        $this->save($project, siteReviewCheck: false);

        self::assertSame([], $this->queued());
    }

    private function save(Project $project, bool $siteReviewCheck): void
    {
        $settings = $this->service(BoardAutomation::class)->settingsOf($project);
        $this->service(SaveBoardAutomationSettingsHandler::class)(new SaveBoardAutomationSettingsCommand(
            project: $project,
            enabled: true,
            commentOnFixQueued: $settings->commentOnFixQueued,
            commentOnStaleApproval: $settings->commentOnStaleApproval,
            syncBehind: $settings->syncBehind,
            mergePullRequests: $settings->mergePullRequests,
            changeBase: $settings->changeBase,
            postWidgetReviews: $settings->postWidgetReviews,
            siteReviewCheck: $siteReviewCheck,
            openEpicPullRequests: $settings->openEpicPullRequests,
        ));
    }

    /** @return list<EvaluateCard> */
    private function queued(): array
    {
        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()),
            static fn (object $message): bool => $message instanceof EvaluateCard,
        ));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
