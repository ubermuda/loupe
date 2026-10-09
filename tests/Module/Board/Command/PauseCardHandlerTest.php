<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Event\CardChanged;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\Board\CardPauseScenario;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Support\DispatchedEvents;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditOutcome;

final class PauseCardHandlerTest extends KernelTestCase
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

    public function test_a_pause_is_stored_audited_and_announced_after_the_commit(): void
    {
        $card = $this->cardIn($this->makeProject('pause-stored'));
        $audit = $this->recordingAuditor();
        $changes = DispatchedEvents::of(self::getContainer(), CardChanged::class);
        $depth = $this->em->getConnection()->getTransactionNestingLevel();

        $pause = $this->pauseHandler($audit->auditor)(new PauseCardCommand($card, 'review-failed', 'fix-on-review', PauseKind::Retries));

        self::assertInstanceOf(CardPause::class, $pause);
        $this->em->clear();
        $stored = $this->em->find(CardPause::class, $pause->id);
        self::assertInstanceOf(CardPause::class, $stored);
        self::assertSame((string) $card->id, (string) $stored->card->id);
        self::assertSame((string) $card->project->id, (string) $stored->project->id);
        self::assertSame('review-failed', $stored->reason);
        self::assertSame('fix-on-review', $stored->ruleId);
        self::assertSame(PauseKind::Retries, $stored->kind);
        self::assertSame('2026-10-02 10:00:00', $stored->createdAt->format('Y-m-d H:i:s'));
        self::assertNull($stored->releasedAt);
        self::assertNull($stored->releaseReason);

        $record = $audit->record('board.card_paused');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame((string) $card->id, $record->subject?->id);
        self::assertSame('retries', $record->context['kind']);
        self::assertSame('fix-on-review', $record->context['ruleId']);

        self::assertCount(1, $changes->events());
        $event = $changes->events()[0];
        self::assertSame((string) $card->id, (string) $event->cardId);
        self::assertSame((string) $card->project->id, (string) $event->projectId);
        self::assertSame(CardChanged::UPDATED, $event->change);
        self::assertFalse($event->contentChanged);
        self::assertSame([$depth], $changes->transactionDepths());
    }

    public function test_a_pause_writes_a_paused_history_row_by_the_app(): void
    {
        $card = $this->cardIn($this->makeProject('pause-history'));

        $this->pauseHandler($this->silentAuditor())(new PauseCardCommand($card, 'review-failed', 'fix-on-review', PauseKind::Retries));

        $rows = $this->pausedRows((string) $card->id);
        self::assertCount(1, $rows);
        self::assertSame('system', $rows[0]['actor_kind']);
        self::assertNull($rows[0]['actor_user_id']);
        self::assertSame(['kind' => 'retries', 'reason' => 'review-failed', 'ruleId' => 'fix-on-review'], json_decode((string) $rows[0]['detail'], true));
        self::assertSame('2026-10-02 10:00:00', substr((string) $rows[0]['occurred_at'], 0, 19));
    }

    public function test_a_second_pause_of_an_active_card_answers_null_and_changes_nothing(): void
    {
        $card = $this->cardIn($this->makeProject('pause-twice'));
        $first = $this->pause($card);
        $audit = $this->recordingAuditor();
        $changes = DispatchedEvents::of(self::getContainer(), CardChanged::class);

        $second = $this->pauseHandler($audit->auditor)(new PauseCardCommand($card, 'work-timed-out', 'implement-on-entry', PauseKind::WorkTimeout));

        self::assertNull($second);
        self::assertTrue($this->em->isOpen());
        self::assertSame(1, $this->countPauses());
        self::assertCount(1, $this->pausedRows((string) $card->id));
        self::assertSame($first, $this->pauseRepository()->findActiveForCard($card));
        self::assertSame([], $audit->operations());
        self::assertSame([], $changes->events());
    }

    public function test_a_released_pause_does_not_block_a_new_one(): void
    {
        $card = $this->cardIn($this->makeProject('pause-again'));
        $first = $this->pause($card);
        self::assertNotNull($first);
        $first->release('owner-resumed', new \DateTimeImmutable());
        $this->em->flush();

        self::assertInstanceOf(CardPause::class, $this->pause($card));
        self::assertSame(2, $this->countPauses());
    }

    /** The workflow engine calls the handler inside its own transaction. */
    public function test_a_pause_inside_an_outer_transaction_lands_with_it(): void
    {
        $card = $this->cardIn($this->makeProject('pause-nested'));
        $changes = DispatchedEvents::of(self::getContainer(), CardChanged::class);
        $depth = $this->em->getConnection()->getTransactionNestingLevel();

        $pause = $this->em->wrapInTransaction(fn (): ?CardPause => $this->pause($card));

        self::assertInstanceOf(CardPause::class, $pause);
        self::assertSame(1, $this->countPauses());
        self::assertCount(1, $changes->events());
        self::assertSame([$depth + 1], $changes->transactionDepths());
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformed(): iterable
    {
        yield 'reason with a space' => ['review failed', 'fix-on-review'];
        yield 'reason in capitals' => ['Review', 'fix-on-review'];
        yield 'reason over the cap' => [str_repeat('a', 65), 'fix-on-review'];
        yield 'rule with free text' => ['review-failed', 'Fix on review'];
        yield 'rule over the cap' => ['review-failed', str_repeat('a', 101)];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_code_is_a_logic_error(string $reason, string $ruleId): void
    {
        $card = $this->cardIn($this->makeProject('pause-malformed'));

        try {
            $this->pause($card, $reason, $ruleId);
            self::fail('Expected a LogicException.');
        } catch (\LogicException) {
        }

        self::assertSame(0, $this->countPauses());
    }

    /** @return list<array<string, mixed>> */
    private function pausedRows(string $cardId): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            "SELECT actor_kind, actor_user_id, detail, occurred_at FROM board_card_events WHERE card_id = :card AND kind = 'paused'",
            ['card' => $cardId],
        );
    }
}
