<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Service\CardStateCode;
use App\Module\Board\Service\CardStateSignalsInterface;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxCardStateSignals;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxCardStateSignalsTest extends KernelTestCase
{
    use CardStateFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_an_open_question_is_a_need_since_it_was_asked(): void
    {
        $project = $this->stateProject('inbox-signal');
        $asked = $this->stateCard($project);
        $quiet = $this->stateCard($project);
        $item = $this->askOwner($asked, InboxItemKind::Question, '2026-10-02 09:15:00');

        $signals = $this->signals()->signalsFor($project, [$asked, $quiet]);

        self::assertSame([(string) $asked->id], array_keys($signals));
        self::assertSame(CardStateCode::OpenQuestion, $signals[(string) $asked->id][0]->code);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:15:00'), $signals[(string) $asked->id][0]->since);
        self::assertSame(['%number%' => $item->number, '%title%' => 'Which option?'], $signals[(string) $asked->id][0]->params);
    }

    public function test_two_questions_keep_the_earliest(): void
    {
        $project = $this->stateProject('inbox-signal-two');
        $card = $this->stateCard($project);
        $this->askOwner($card, InboxItemKind::Question, '2026-10-02 10:00:00');
        $this->askOwner($card, InboxItemKind::Todo, '2026-10-02 09:00:00');

        $signals = $this->signals()->signalsFor($project, [$card]);

        self::assertCount(1, $signals[(string) $card->id]);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:00:00'), $signals[(string) $card->id][0]->since);
    }

    public function test_a_closed_item_and_a_wait_item_are_no_need(): void
    {
        $project = $this->stateProject('inbox-signal-closed');
        $card = $this->stateCard($project);
        $this->askOwner($card)->state = InboxItemState::Answered;
        $this->askOwner($card, InboxItemKind::Wait);
        $this->em()->flush();

        self::assertSame([], $this->signals()->signalsFor($project, [$card]));
    }

    private function signals(): CardStateSignalsInterface
    {
        $signals = self::getContainer()->get(InboxCardStateSignals::class);
        self::assertInstanceOf(InboxCardStateSignals::class, $signals);

        return $signals;
    }
}
