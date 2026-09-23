<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardLinkRepositoryTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardLinkRepository $links;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $links = self::getContainer()->get(CardLinkRepository::class);
        self::assertInstanceOf(CardLinkRepository::class, $links);
        $this->links = $links;
    }

    public function test_find_for_cards_keys_a_row_under_both_of_its_cards(): void
    {
        $project = $this->makeProject('links-both');
        [$a, $b, $c] = [$this->cardIn($project), $this->cardIn($project), $this->cardIn($project)];
        $this->em->persist(new CardLink($a, $b, CardLinkKind::Blocks));
        $this->em->flush();
        $this->em->clear();

        $byCard = $this->links->findForCards([$this->reload($a), $this->reload($b), $this->reload($c)]);

        self::assertSame([(string) $a->id, (string) $b->id], array_keys($byCard));
        self::assertCount(1, $byCard[(string) $a->id]);
        self::assertSame($byCard[(string) $a->id][0], $byCard[(string) $b->id][0]);

        $link = $byCard[(string) $a->id][0];
        self::assertSame(CardLinkKind::Blocks, $link->kindFor($link->source));
        self::assertSame((string) $a->id, (string) $link->source->id);
        self::assertSame(CardLinkKind::BlockedBy, $link->kindFor($link->target));
    }

    public function test_find_for_cards_keys_a_row_under_the_one_card_asked_for(): void
    {
        $project = $this->makeProject('links-one');
        [$a, $b] = [$this->cardIn($project), $this->cardIn($project)];
        $this->em->persist(new CardLink($a, $b, CardLinkKind::RelatesTo));
        $this->em->flush();
        $this->em->clear();

        $byCard = $this->links->findForCards([$this->reload($b)]);

        self::assertSame([(string) $b->id], array_keys($byCard));
        self::assertCount(1, $byCard[(string) $b->id]);
    }

    public function test_find_for_cards_with_no_cards_returns_nothing(): void
    {
        self::assertSame([], $this->links->findForCards([]));
    }

    public function test_find_for_card_reads_both_directions_in_link_order(): void
    {
        $project = $this->makeProject('links-card');
        [$a, $b, $c] = [$this->cardIn($project), $this->cardIn($project), $this->cardIn($project)];
        $this->em->persist(new CardLink($a, $b, CardLinkKind::Blocks, new \DateTimeImmutable('2026-01-01 10:00')));
        $this->em->persist(new CardLink($c, $a, CardLinkKind::RelatesTo, new \DateTimeImmutable('2026-01-01 09:00')));
        $this->em->persist(new CardLink($b, $c, CardLinkKind::Blocks));
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->reload($a);
        $links = $this->links->findForCard($reloaded);

        self::assertCount(2, $links);
        self::assertSame((string) $c->id, (string) $links[0]->otherThan($reloaded)->id);
        self::assertSame((string) $b->id, (string) $links[1]->otherThan($reloaded)->id);
        self::assertSame(CardLinkKind::Blocks, $links[1]->kindFor($reloaded));
    }

    public function test_deleting_a_card_removes_its_links(): void
    {
        $project = $this->makeProject('links-cascade');
        [$a, $b, $c] = [$this->cardIn($project), $this->cardIn($project), $this->cardIn($project)];
        $this->em->persist(new CardLink($a, $b, CardLinkKind::Blocks));
        $this->em->persist(new CardLink($c, $a, CardLinkKind::RelatesTo));
        $this->em->persist(new CardLink($b, $c, CardLinkKind::RelatesTo));
        $this->em->flush();
        $this->em->clear();

        // Guard: both of card A's rows exist before the delete.
        self::assertCount(2, $this->links->findForCard($this->reload($a)));

        $this->em->getConnection()->executeStatement('DELETE FROM board_cards WHERE id = ?', [(string) $a->id]);
        $this->em->clear();

        self::assertSame(1, $this->links->count([]));
        self::assertCount(1, $this->links->findForCard($this->reload($b)));
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
