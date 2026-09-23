<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Service\CardLinkSync;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardLinkSyncTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardLinkSync $sync;
    private CardLinkRepository $links;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $sync = self::getContainer()->get(CardLinkSync::class);
        self::assertInstanceOf(CardLinkSync::class, $sync);
        $this->sync = $sync;

        $links = self::getContainer()->get(CardLinkRepository::class);
        self::assertInstanceOf(CardLinkRepository::class, $links);
        $this->links = $links;
    }

    public function test_blocked_by_stores_the_other_card_as_the_blocking_source(): void
    {
        $project = $this->makeProject('sync-insert');
        [$a, $b, $c] = [$this->cardIn($project), $this->cardIn($project), $this->cardIn($project)];

        $this->write($a, [[$b, CardLinkKind::BlockedBy], [$c, CardLinkKind::RelatesTo]]);

        self::assertSame(2, $this->links->count([]));
        $rows = $this->rowsOf($a);
        self::assertSame([(string) $b->id, (string) $a->id, 'blocks'], $rows[(string) $b->id]);
        self::assertSame([(string) $a->id, (string) $c->id, 'relates-to'], $rows[(string) $c->id]);
    }

    public function test_a_pair_with_the_same_kind_keeps_its_row_whichever_side_wrote_it(): void
    {
        $project = $this->makeProject('sync-keep');
        [$a, $b] = [$this->cardIn($project), $this->cardIn($project)];
        $this->em->persist($existing = new CardLink($b, $a, CardLinkKind::RelatesTo));
        $this->em->flush();
        $id = $existing->id;

        $this->write($a, [[$b, CardLinkKind::RelatesTo]]);

        self::assertSame(1, $this->links->count([]));
        $link = $this->links->findForCard($this->reload($a))[0];
        self::assertTrue($id?->equals($link->id));
        self::assertSame((string) $b->id, (string) $link->source->id);
    }

    public function test_a_changed_kind_changes_the_row_in_place_and_swaps_its_direction(): void
    {
        $project = $this->makeProject('sync-change');
        [$a, $b] = [$this->cardIn($project), $this->cardIn($project)];
        $this->em->persist($existing = new CardLink($a, $b, CardLinkKind::Blocks));
        $this->em->flush();
        $id = $existing->id;

        // B read "blocked-by A". B now says it blocks A, so the row turns round.
        $this->write($b, [[$a, CardLinkKind::Blocks]]);
        self::assertSame(1, $this->links->count([]));
        $rows = $this->rowsOf($a);
        self::assertSame([(string) $b->id, (string) $a->id, 'blocks'], $rows[(string) $b->id]);

        // Then relates-to, written from A: the same row again.
        $this->write($a, [[$b, CardLinkKind::RelatesTo]]);
        self::assertSame(1, $this->links->count([]));
        $link = $this->links->findForCard($this->reload($a))[0];
        self::assertTrue($id?->equals($link->id));
        self::assertSame(CardLinkKind::RelatesTo, $link->kind);
    }

    public function test_a_row_with_no_wanted_pair_is_removed_and_other_cards_links_stay(): void
    {
        $project = $this->makeProject('sync-remove');
        [$a, $b, $c] = [$this->cardIn($project), $this->cardIn($project), $this->cardIn($project)];
        $this->em->persist(new CardLink($b, $a, CardLinkKind::Blocks));
        $this->em->persist(new CardLink($a, $c, CardLinkKind::RelatesTo));
        $this->em->persist(new CardLink($b, $c, CardLinkKind::RelatesTo));
        $this->em->flush();

        $this->write($a, [[$c, CardLinkKind::RelatesTo]]);

        self::assertSame(2, $this->links->count([]));
        self::assertSame([(string) $c->id], array_keys($this->rowsOf($a)));
        self::assertCount(2, $this->links->findForCard($this->reload($c)));
    }

    public function test_an_empty_set_removes_every_link_of_the_card(): void
    {
        $project = $this->makeProject('sync-clear');
        [$a, $b] = [$this->cardIn($project), $this->cardIn($project)];
        $this->em->persist(new CardLink($b, $a, CardLinkKind::Blocks));
        $this->em->flush();

        $this->write($a, []);

        self::assertSame(0, $this->links->count([]));
    }

    /** @param list<array{Card, CardLinkKind}> $wanted */
    private function write(Card $card, array $wanted): void
    {
        // Every card managed again, as a handler holds it after its own lock.
        $this->sync->sync(
            $this->reload($card),
            array_map(fn (array $pair): array => [$this->reload($pair[0]), $pair[1]], $wanted),
        );
        $this->em->flush();
        $this->em->clear();
    }

    /** @return array<string, array{string, string, string}> other card id => [source id, target id, stored kind] */
    private function rowsOf(Card $card): array
    {
        $reloaded = $this->reload($card);
        $rows = [];
        foreach ($this->links->findForCard($reloaded) as $link) {
            $rows[(string) $link->otherThan($reloaded)->id] = [(string) $link->source->id, (string) $link->target->id, $link->kind->value];
        }

        return $rows;
    }

    private function cardIn(Project $project): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand($project, 'Ship it', 'Body', CardType::Feature));
    }

    private function reload(Card $card): Card
    {
        return $this->em->find(Card::class, $card->id) ?? throw new \LogicException('The card must exist.');
    }
}
