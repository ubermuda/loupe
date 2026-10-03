<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\ReleaseCardPauseCommand;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Event\CardChanged;
use App\Tests\Module\Board\CardPauseScenario;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Support\DispatchedEvents;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditOutcome;

final class ReleaseCardPauseHandlerTest extends KernelTestCase
{
    use BoardToolScenario;
    use CardPauseScenario;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_a_release_is_stored_audited_and_announced_after_the_commit(): void
    {
        $card = $this->cardIn($this->makeProject('release-stored'));
        $pause = $this->pause($card) ?? throw new \LogicException('The card had no pause.');
        $audit = $this->recordingAuditor();
        $changes = DispatchedEvents::of(self::getContainer(), CardChanged::class);
        $depth = $this->em->getConnection()->getTransactionNestingLevel();

        self::assertTrue($this->releaseHandler($audit->auditor)(new ReleaseCardPauseCommand($pause, 'owner-resumed')));

        $this->em->clear();
        $stored = $this->em->find(CardPause::class, $pause->id);
        self::assertInstanceOf(CardPause::class, $stored);
        self::assertSame('2026-10-02 11:00:00', $stored->releasedAt?->format('Y-m-d H:i:s'));
        self::assertSame('owner-resumed', $stored->releaseReason);
        self::assertNull($this->pauseRepository()->findActiveForCard($stored->card));

        $record = $audit->record('board.card_pause_released');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame((string) $card->id, $record->subject?->id);
        self::assertSame('owner-resumed', $record->context['reason']);

        self::assertCount(1, $changes->events());
        self::assertSame((string) $card->id, (string) $changes->events()[0]->cardId);
        self::assertSame(CardChanged::UPDATED, $changes->events()[0]->change);
        self::assertSame([$depth], $changes->transactionDepths());
    }

    public function test_a_second_release_answers_false_and_changes_nothing(): void
    {
        $card = $this->cardIn($this->makeProject('release-twice'));
        $pause = $this->pause($card) ?? throw new \LogicException('The card had no pause.');
        self::assertTrue($this->releaseHandler($this->silentAuditor())(new ReleaseCardPauseCommand($pause, 'owner-resumed')));
        $audit = $this->recordingAuditor();
        $changes = DispatchedEvents::of(self::getContainer(), CardChanged::class);

        self::assertFalse($this->releaseHandler($audit->auditor, '2026-10-02 12:00:00')(new ReleaseCardPauseCommand($pause, 'rule-cleared')));

        $this->em->clear();
        $stored = $this->em->find(CardPause::class, $pause->id);
        self::assertSame('2026-10-02 11:00:00', $stored?->releasedAt?->format('Y-m-d H:i:s'));
        self::assertSame('owner-resumed', $stored->releaseReason);
        self::assertSame([], $audit->operations());
        self::assertSame([], $changes->events());
    }

    /** A copy loaded before another release committed still reads the release. */
    public function test_a_release_reads_the_pause_fresh(): void
    {
        $card = $this->cardIn($this->makeProject('release-stale'));
        $pause = $this->pause($card) ?? throw new \LogicException('The card had no pause.');
        $this->em->getConnection()->executeStatement(
            "UPDATE card_pauses SET released_at = '2026-10-02 10:30:00', release_reason = 'rule-cleared' WHERE id = :id",
            ['id' => (string) $pause->id],
        );

        self::assertFalse($this->releaseHandler($this->silentAuditor())(new ReleaseCardPauseCommand($pause, 'owner-resumed')));
        self::assertSame('rule-cleared', $pause->releaseReason);
    }

    public function test_a_malformed_reason_is_a_logic_error(): void
    {
        $card = $this->cardIn($this->makeProject('release-malformed'));
        $pause = $this->pause($card) ?? throw new \LogicException('The card had no pause.');

        $this->expectException(\LogicException::class);

        $this->releaseHandler($this->silentAuditor())(new ReleaseCardPauseCommand($pause, 'Owner resumed'));
    }
}
