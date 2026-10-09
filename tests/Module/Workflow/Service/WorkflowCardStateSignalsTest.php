<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Service\CardStateCode;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Service\WorkflowCardStateSignals;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkflowCardStateSignalsTest extends KernelTestCase
{
    use CardStateFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_stage_document_in_review_is_a_need_since_its_newest_version(): void
    {
        $project = $this->stateProject('workflow-signal-document');
        $card = $this->stateCard($project, 'tech-design');
        $document = $this->reviewDocument($card, 'tech-design', '2026-10-02 09:20:00');

        $signals = $this->signals()->signalsFor($project, [$card]);

        self::assertSame(CardStateCode::DocumentInReview, $signals[(string) $card->id][0]->code);
        self::assertSame(['%title%' => $document->title], $signals[(string) $card->id][0]->params);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:20:00'), $signals[(string) $card->id][0]->since);
    }

    public function test_a_document_that_is_approved_or_that_the_slot_ignores_is_no_need(): void
    {
        $project = $this->stateProject('workflow-signal-ignored');
        $approved = $this->stateCard($project, 'tech-design');
        $this->reviewDocument($approved)->status = DocumentStatus::Approved;
        $ignored = $this->stateCard($project, 'tech-design');
        $this->reviewDocument($ignored, 'meeting-notes');
        $this->em()->flush();

        self::assertSame([], $this->signals()->signalsFor($project, [$approved, $ignored]));
    }

    public function test_a_card_held_by_a_blocker_waits_for_the_blocker_of_the_lowest_number(): void
    {
        $project = $this->stateProject('workflow-signal-blocker');
        $card = $this->stateCard($project, 'tech-design');
        $first = $this->holdByBlocker($card, '2026-10-02 09:10:00');
        $this->holdByBlocker($card, '2026-10-02 09:10:00');

        $signals = $this->signals()->signalsFor($project, [$card]);

        self::assertCount(1, $signals[(string) $card->id]);
        self::assertSame(CardStateCode::HeldByBlocker, $signals[(string) $card->id][0]->code);
        self::assertSame(['%number%' => $first->number, '%title%' => $first->title], $signals[(string) $card->id][0]->params);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:10:00'), $signals[(string) $card->id][0]->since);
    }

    public function test_a_card_with_no_stamp_waits_for_nothing(): void
    {
        $project = $this->stateProject('workflow-signal-no-stamp');
        $card = $this->stateCard($project, 'tech-design');

        self::assertSame([], $this->signals()->signalsFor($project, [$card]));
    }

    private function signals(): WorkflowCardStateSignals
    {
        $signals = self::getContainer()->get(WorkflowCardStateSignals::class);
        self::assertInstanceOf(WorkflowCardStateSignals::class, $signals);

        return $signals;
    }
}
