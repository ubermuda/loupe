<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Entity;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;

final class CardLinkTest extends TestCase
{
    public function test_inverse_swaps_blocks_and_blocked_by_and_keeps_relates_to(): void
    {
        self::assertSame(CardLinkKind::RelatesTo, CardLinkKind::RelatesTo->inverse());
        self::assertSame(CardLinkKind::BlockedBy, CardLinkKind::Blocks->inverse());
        self::assertSame(CardLinkKind::Blocks, CardLinkKind::BlockedBy->inverse());
    }

    public function test_each_side_reads_its_own_kind(): void
    {
        [$a, $b] = $this->cards(2);

        $link = new CardLink($a, $b, CardLinkKind::Blocks);

        self::assertSame(CardLinkKind::Blocks, $link->kindFor($a));
        self::assertSame(CardLinkKind::BlockedBy, $link->kindFor($b));
        self::assertSame($b, $link->otherThan($a));
        self::assertSame($a, $link->otherThan($b));
    }

    public function test_relates_to_reads_the_same_from_both_sides(): void
    {
        [$a, $b] = $this->cards(2);

        $link = new CardLink($a, $b, CardLinkKind::RelatesTo);

        self::assertSame(CardLinkKind::RelatesTo, $link->kindFor($a));
        self::assertSame(CardLinkKind::RelatesTo, $link->kindFor($b));
    }

    public function test_the_constructor_refuses_to_store_blocked_by(): void
    {
        [$a, $b] = $this->cards(2);

        $this->expectException(\LogicException::class);

        $link = new CardLink($a, $b, CardLinkKind::BlockedBy);
        self::fail('The link stored blocked-by as '.$link->kind->value);
    }

    public function test_a_later_write_refuses_to_store_blocked_by(): void
    {
        [$a, $b] = $this->cards(2);
        $link = new CardLink($a, $b, CardLinkKind::RelatesTo);

        $this->expectException(\LogicException::class);

        $link->kind = CardLinkKind::BlockedBy;
    }

    public function test_a_later_write_stores_blocks(): void
    {
        [$a, $b] = $this->cards(2);
        $link = new CardLink($a, $b, CardLinkKind::RelatesTo);

        $link->kind = CardLinkKind::Blocks;

        self::assertSame(CardLinkKind::Blocks, $link->kind);
    }

    public function test_kind_for_refuses_a_card_on_neither_side(): void
    {
        [$a, $b, $c] = $this->cards(3);
        $link = new CardLink($a, $b, CardLinkKind::Blocks);

        $this->expectException(\LogicException::class);

        $link->kindFor($c);
    }

    public function test_other_than_refuses_a_card_on_neither_side(): void
    {
        [$a, $b, $c] = $this->cards(3);
        $link = new CardLink($a, $b, CardLinkKind::Blocks);

        $this->expectException(\LogicException::class);

        $link->otherThan($c);
    }

    /** @return list<Card> */
    private function cards(int $count): array
    {
        $project = new Project(new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x'), 'Links');
        $column = new BoardColumn($project, 'Backlog', 'backlog', 0);

        $cards = [];
        for ($number = 1; $number <= $count; ++$number) {
            $cards[] = new Card($project, $column, 'Card '.$number, 'Body', $number);
        }

        return $cards;
    }
}
