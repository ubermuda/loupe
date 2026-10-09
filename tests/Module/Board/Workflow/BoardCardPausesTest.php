<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\CardPause;
use App\Module\Board\Workflow\BoardCardPauses;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\Board\CardPauseScenario;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BoardCardPausesTest extends KernelTestCase
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

    public function test_a_pause_maps_to_a_view_with_every_field(): void
    {
        $card = $this->cardIn($this->makeProject('pauses-view'));
        $pause = $this->pause($card, 'review-failed', 'fix-on-review', PauseKind::Retries);
        self::assertInstanceOf(CardPause::class, $pause);
        $pause->release('person', new \DateTimeImmutable('2026-10-02 11:00:00'));

        $view = BoardCardPauses::view($pause);

        self::assertSame((string) $pause->id, (string) $view->id);
        self::assertSame((string) $card->id, (string) $view->cardId);
        self::assertSame((string) $card->project->id, (string) $view->projectId);
        self::assertSame(['review-failed', 'fix-on-review', PauseKind::Retries], [$view->reason, $view->ruleId, $view->kind]);
        self::assertEquals($pause->createdAt, $view->createdAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 11:00:00'), $view->releasedAt);
        self::assertSame('person', $view->releaseReason);
    }

    public function test_the_active_and_the_latest_pause_read_through_the_card_id(): void
    {
        $card = $this->cardIn($this->makeProject('pauses-find'));
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
        $pauses = $this->pauses();
        self::assertNull($pauses->findActive($cardId));
        self::assertNull($pauses->findLatest($cardId));
        self::assertNull($pauses->findActive(Uuid::v7()));

        $made = $pauses->pause($card->snapshot(), 'review-failed', 'fix-on-review', PauseKind::Retries);

        self::assertNotNull($made);
        self::assertEquals($made, $pauses->findActive($cardId));
        self::assertEquals($made, $pauses->findLatest($cardId));
        self::assertNull($pauses->pause($card->snapshot(), 'other', 'fix-on-review', PauseKind::Retries), 'a second pause is refused');
    }

    public function test_a_release_answers_the_released_view_once(): void
    {
        $card = $this->cardIn($this->makeProject('pauses-release'));
        $pauses = $this->pauses();
        $made = $pauses->pause($card->snapshot(), 'review-failed', 'fix-on-review', PauseKind::Retries);
        self::assertNotNull($made);

        $released = $pauses->release($made, 'facts-changed');

        self::assertNotNull($released);
        self::assertSame('facts-changed', $released->releaseReason);
        self::assertNotNull($released->releasedAt);
        self::assertNull($pauses->findActive($card->id ?? throw new \LogicException()));
        self::assertNull($pauses->release($made, 'facts-changed'), 'a second release changes nothing');
    }

    public function test_a_recorded_release_writes_a_history_row_with_the_actor(): void
    {
        $card = $this->cardIn($this->makeProject('pauses-record'));
        $pauses = $this->pauses();
        $made = $pauses->pause($card->snapshot(), 'review-failed', 'fix-on-review', PauseKind::Retries);
        self::assertNotNull($made);
        $released = $pauses->release($made, 'run-resumed');
        self::assertNotNull($released);

        $pauses->recordReleased($released, Actor::System, null);
        $this->em->flush();

        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT actor_kind, actor_user_id, detail FROM board_card_events WHERE card_id = ? AND kind = 'pause-released'",
            [(string) $card->id],
        );
        self::assertCount(1, $rows);
        self::assertSame('system', $rows[0]['actor_kind']);
        self::assertNull($rows[0]['actor_user_id']);
        self::assertSame(['kind' => 'retries', 'reason' => 'review-failed', 'ruleId' => 'fix-on-review'], json_decode((string) $rows[0]['detail'], true));
    }

    private function pauses(): BoardCardPauses
    {
        $cards = self::getContainer()->get(\App\Module\Board\Repository\CardRepository::class);
        self::assertInstanceOf(\App\Module\Board\Repository\CardRepository::class, $cards);
        $audit = $this->silentAuditor();

        return new BoardCardPauses($cards, $this->pauseRepository(), $this->cardEventRepository(), $this->pauseHandler($audit), $this->releaseHandler($audit), $this->em);
    }
}
