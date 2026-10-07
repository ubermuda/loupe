<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardActivityLinks;
use App\Module\Project\Entity\Project;
use App\Outbox\ActivityLink;
use App\Outbox\Entity\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CardActivityLinksTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Project $project;
    private BoardColumn $backlog;
    private BoardColumn $done;
    private Card $card;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $owner = new User(fullName: 'Owner', email: 'card-activity-links@example.com', password: 'x');
        $this->project = new Project($owner, 'Card activity links');
        $this->backlog = new BoardColumn($this->project, 'board.card.status.backlog', 'backlog', 0);
        $this->done = new BoardColumn($this->project, 'Shipped', 'done', 1, terminal: true);
        $this->card = new Card($this->project, $this->done, 'Fix login', '', 7);
        foreach ([$owner, $this->project, $this->backlog, $this->done, $this->card] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
    }

    public function test_a_move_names_the_translated_columns_it_left_and_entered(): void
    {
        $move = $this->event('board.card_moved', ['fromStatus' => 'backlog', 'toStatus' => 'done']);

        $link = $this->links($move)[(string) $move->id];

        self::assertSame('#7 Fix login', $link->label);
        self::assertSame('#7 Backlog → Shipped', $link->subject);
    }

    public function test_a_slug_with_no_column_shows_the_slug(): void
    {
        $move = $this->event('board.card_moved', ['fromStatus' => 'archived', 'toStatus' => 'done']);

        self::assertSame('#7 archived → Shipped', $this->links($move)[(string) $move->id]->subject);
    }

    public function test_a_move_with_no_slugs_keeps_the_card_title(): void
    {
        $move = $this->event('board.card_moved', ['toStatus' => 'done']);

        self::assertSame('#7 Fix login', $this->links($move)[(string) $move->id]->subject);
    }

    public function test_another_card_event_keeps_the_card_title(): void
    {
        $created = $this->event('board.card_created', ['fromStatus' => 'backlog', 'toStatus' => 'done']);

        self::assertSame('#7 Fix login', $this->links($created)[(string) $created->id]->subject);
    }

    public function test_the_columns_are_read_once_and_only_for_a_move(): void
    {
        $moves = [
            $this->event('board.card_moved', ['fromStatus' => 'backlog', 'toStatus' => 'done']),
            $this->event('board.card_moved', ['fromStatus' => 'done', 'toStatus' => 'backlog']),
        ];
        $columns = $this->createMock(BoardColumnRepository::class);
        $columns->expects($this->once())->method('findForProject')->with($this->project)->willReturn([$this->backlog, $this->done]);

        $links = $this->provider($columns)->linksFor($this->project, $moves);

        self::assertSame('#7 Shipped → Backlog', $links[(string) $moves[1]->id]->subject);

        $untouched = $this->createMock(BoardColumnRepository::class);
        $untouched->expects($this->never())->method('findForProject');
        $created = $this->event('board.card_created', []);

        self::assertArrayHasKey((string) $created->id, $this->provider($untouched)->linksFor($this->project, [$created]));
    }

    /** @return array<string, ActivityLink> */
    private function links(OutboxEvent $event): array
    {
        $columns = self::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $columns);

        return $this->provider($columns)->linksFor($this->project, [$event]);
    }

    private function provider(BoardColumnRepository $columns): CardActivityLinks
    {
        $container = self::getContainer();
        $cards = $container->get(CardRepository::class);
        $urls = $container->get(UrlGeneratorInterface::class);
        $translator = $container->get(TranslatorInterface::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        self::assertInstanceOf(UrlGeneratorInterface::class, $urls);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return new CardActivityLinks($cards, $columns, $urls, $translator);
    }

    /** @param array<string, string> $fields */
    private function event(string $type, array $fields): OutboxEvent
    {
        $event = new OutboxEvent($this->project, $type, 'topic', json_encode([
            'subject' => ['type' => 'card', 'id' => (string) $this->card->id],
            ...$fields,
        ], \JSON_THROW_ON_ERROR));
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }
}
