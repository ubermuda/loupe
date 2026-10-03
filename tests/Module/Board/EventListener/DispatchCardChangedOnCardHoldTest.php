<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\EventListener\DispatchCardChangedOnCardHold;
use App\Module\Bridge\Event\CardHeld;
use App\Module\Bridge\Event\CardHoldsReleased;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;

final class DispatchCardChangedOnCardHoldTest extends TestCase
{
    /** @var list<CardChanged> */
    private array $changes = [];

    private DispatchCardChangedOnCardHold $listener;

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(CardChanged::class, function (CardChanged $event): void {
            $this->changes[] = $event;
        });
        $this->listener = new DispatchCardChangedOnCardHold($dispatcher);
    }

    public function test_a_hold_redraws_the_tile_of_its_card(): void
    {
        $projectId = Uuid::v7();
        $cardId = Uuid::v7();

        $this->listener->onCardHeld(new CardHeld($projectId, $cardId));

        self::assertCount(1, $this->changes);
        self::assertTrue($projectId->equals($this->changes[0]->projectId));
        self::assertTrue($cardId->equals($this->changes[0]->cardId));
        self::assertSame(CardChanged::UPDATED, $this->changes[0]->change);
        self::assertFalse($this->changes[0]->contentChanged);
    }

    public function test_a_release_redraws_the_tile_of_each_released_card(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();

        $this->listener->onCardHoldsReleased(new CardHoldsReleased(Uuid::v7(), [$first, $second]));

        self::assertSame(
            [$first->toRfc4122(), $second->toRfc4122()],
            array_map(static fn (CardChanged $change): string => $change->cardId->toRfc4122(), $this->changes),
        );
        self::assertSame([CardChanged::UPDATED, CardChanged::UPDATED], array_map(static fn (CardChanged $change): string => $change->change, $this->changes));
    }
}
