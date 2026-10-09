<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Workflow\BoardCardPauses;
use App\Module\Workflow\Action\ActionOutcome;
use App\Module\Workflow\Action\ReleasePause;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Template\ActionType;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Ubermuda\AuditBundle\Auditor;

final class ReleasePauseTest extends KernelTestCase
{
    use ActionScenario;

    public function test_it_releases_the_active_pause_with_the_reason(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('release-match'), 'next');
        $pause = $this->pause($card, 'owner-review');

        $outcome = $this->release($card, 'owner_review');

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertNotNull($pause->releasedAt);
        self::assertSame('released-by-rule', $pause->releaseReason);
        self::assertNull($this->service(CardPauseRepository::class)->findActiveForCard($card));
    }

    public function test_a_pause_with_another_reason_stays(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('release-other'), 'next');
        $pause = $this->pause($card, 'owner-review');

        $outcome = $this->release($card, 'checks-red');

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertNull($pause->releasedAt);
    }

    public function test_a_card_with_no_pause_is_done(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('release-none'), 'next');

        self::assertEquals(ActionOutcome::done(), $this->release($card, 'owner-review'));
    }

    private function pause(Card $card, string $reason): CardPause
    {
        $pause = $this->pauseHandler()(new PauseCardCommand($card, $reason, 'hold-rule', PauseKind::Rule));
        self::assertNotNull($pause);

        return $pause;
    }

    /** Built by hand, so each pause and release takes a fixed time. */
    private function pauseHandler(): PauseCardHandler
    {
        return new PauseCardHandler($this->service(CardPauseRepository::class), $this->em(), new MockClock('2026-10-02 10:00:00'), $this->service(Auditor::class), $this->service(EventDispatcherInterface::class), $this->service(CardEventRepository::class));
    }

    private function cardPauses(): CardPauses
    {
        $clock = new MockClock('2026-10-02 11:00:00');
        $auditor = $this->service(Auditor::class);
        $events = $this->service(EventDispatcherInterface::class);
        $cardPauses = $this->service(CardPauseRepository::class);

        return new BoardCardPauses(
            $this->service(CardRepository::class),
            $cardPauses,
            $this->service(CardEventRepository::class),
            new PauseCardHandler($cardPauses, $this->em(), $clock, $auditor, $events, $this->service(CardEventRepository::class)),
            $this->releaseHandler(),
            $this->em(),
        );
    }

    private function releaseHandler(): ReleaseCardPauseHandler
    {
        return new ReleaseCardPauseHandler($this->em(), new MockClock('2026-10-02 11:00:00'), $this->service(Auditor::class), $this->service(EventDispatcherInterface::class));
    }

    private function release(Card $card, string $reason): ActionOutcome
    {
        $action = new ReleasePause($this->cardPauses());

        return $action->run($this->rule(ActionType::Release, ['reason' => $reason]), $card->snapshot(), FactsMother::facts(), $this->state($card));
    }
}
