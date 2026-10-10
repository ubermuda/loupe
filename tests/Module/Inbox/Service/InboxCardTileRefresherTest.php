<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Event\CardChanged;
use App\Module\Inbox\Service\InboxCardTileRefresher;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class InboxCardTileRefresherTest extends KernelTestCase
{
    use CardStateFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_redraws_each_card_an_item_names(): void
    {
        $project = $this->stateProject('inbox-tiles');
        $card = $this->stateCard($project);
        $item = $this->askOwner($card);
        $changes = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(CardChanged::class, static function (CardChanged $event) use (&$changes): void {
            $changes[] = $event;
        });

        new InboxCardTileRefresher($dispatcher)->refresh($item);

        self::assertCount(1, $changes);
        self::assertTrue($card->id?->equals($changes[0]->cardId));
        self::assertSame(CardChanged::UPDATED, $changes[0]->change);
        self::assertFalse($changes[0]->contentChanged);
    }

    public function test_an_item_that_names_no_card_redraws_nothing(): void
    {
        $project = $this->stateProject('inbox-tiles-none');
        $item = $this->askOwner($this->stateCard($project));
        $item->cards->clear();
        $changes = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(CardChanged::class, static function (CardChanged $event) use (&$changes): void {
            $changes[] = $event;
        });

        new InboxCardTileRefresher($dispatcher)->refresh($item);

        self::assertSame([], $changes);
    }
}
