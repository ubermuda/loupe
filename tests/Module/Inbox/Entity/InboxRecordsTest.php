<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Entity;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The deletes run as plain SQL, so the foreign keys do the work. Board and
 * Review delete their rows without knowing an inbox link exists.
 */
final class InboxRecordsTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
    }

    public function test_deleting_a_card_removes_its_item_links_and_clears_the_ask_card(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'inbox-card'), 'inbox');
        $card = $this->card($this->em, $project);
        $item = $this->item($this->em, $project);
        $item->cards->add(new InboxItemCard($item, $card));
        $ask = $this->ask($this->em, $project);
        $ask->card = $card;
        $this->em->flush();
        $askId = (string) $ask->id;
        $itemId = (string) $item->id;

        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM inbox_item_cards WHERE item_id = :id', $itemId));
        self::assertSame((string) $card->id, $this->connection->fetchOne('SELECT card_id FROM inbox_asks WHERE id = :id', ['id' => $askId]));

        $this->connection->executeStatement('DELETE FROM board_cards WHERE id = :id', ['id' => (string) $card->id]);

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM inbox_item_cards WHERE item_id = :id', $itemId));
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM inbox_asks WHERE id = :id', $askId));
        self::assertNull($this->connection->fetchOne('SELECT card_id FROM inbox_asks WHERE id = :id', ['id' => $askId]));
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM inbox_items WHERE id = :id', $itemId));
    }

    public function test_deleting_a_document_removes_its_item_links(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'inbox-document'), 'inbox');
        $document = $this->document($this->em, $project);
        $item = $this->item($this->em, $project);
        $item->documents->add(new InboxItemDocument($item, $document));
        $this->em->flush();
        $itemId = (string) $item->id;

        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM inbox_item_documents WHERE item_id = :id', $itemId));

        $this->connection->executeStatement('DELETE FROM documents WHERE id = :id', ['id' => (string) $document->id]);

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM inbox_item_documents WHERE item_id = :id', $itemId));
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM inbox_items WHERE id = :id', $itemId));
    }

    public function test_deleting_an_ask_removes_its_memberships_and_keeps_the_items(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'inbox-ask'), 'inbox');
        $item = $this->item($this->em, $project);
        $ask = $this->ask($this->em, $project);
        $ask->items->add(new InboxAskItem($ask, $item));
        $this->em->flush();
        $askId = (string) $ask->id;

        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM inbox_ask_items WHERE ask_id = :id', $askId));

        $this->connection->executeStatement('DELETE FROM inbox_asks WHERE id = :id', ['id' => $askId]);

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM inbox_ask_items WHERE ask_id = :id', $askId));
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM inbox_items WHERE id = :id', (string) $item->id));
    }

    public function test_an_item_joins_an_ask_once(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'inbox-ask-twice'), 'inbox');
        $item = $this->item($this->em, $project);
        $ask = $this->ask($this->em, $project);
        $this->em->persist(new InboxAskItem($ask, $item));
        $this->em->persist(new InboxAskItem($ask, $item));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function test_one_session_holds_one_open_ask(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'inbox-open-ask-twice'), 'inbox');
        $sessionId = Uuid::v4();
        $this->em->persist(new InboxAsk(project: $project, sessionId: $sessionId));
        $this->em->persist(new InboxAsk(project: $project, sessionId: $sessionId));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function test_a_session_opens_a_new_ask_once_its_ask_is_closed(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'inbox-reopen-ask'), 'inbox');
        $sessionId = Uuid::v4();
        $closed = new InboxAsk(project: $project, sessionId: $sessionId);
        $closed->closedAt = new \DateTimeImmutable();
        $this->em->persist($closed);
        $this->em->persist(new InboxAsk(project: $project, sessionId: $sessionId));
        $this->em->flush();

        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM inbox_asks WHERE session_id = :id', (string) $sessionId));
    }

    public function test_an_item_links_a_card_once(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'inbox-card-twice'), 'inbox');
        $card = $this->card($this->em, $project);
        $item = $this->item($this->em, $project);
        $this->em->persist(new InboxItemCard($item, $card));
        $this->em->persist(new InboxItemCard($item, $card));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function test_the_item_number_counts_from_one_inside_each_project(): void
    {
        $owner = $this->owner($this->em, 'inbox-number');
        $first = $this->project($this->em, $owner, 'first');
        $second = $this->project($this->em, $owner, 'second');
        $this->em->flush();

        $items = self::getContainer()->get(InboxItemRepository::class);
        self::assertInstanceOf(InboxItemRepository::class, $items);

        self::assertSame(1, $items->nextNumber($first));
        $this->item($this->em, $first, $items->nextNumber($first));
        $this->em->flush();
        self::assertSame(2, $items->nextNumber($first));
        $this->item($this->em, $first, $items->nextNumber($first));
        $this->em->flush();

        self::assertSame(3, $items->nextNumber($first));
        self::assertSame(1, $items->nextNumber($second));
    }

    public function test_two_items_of_one_project_cannot_share_a_number(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'inbox-number-twice'), 'inbox');
        $this->item($this->em, $project, 4);
        $this->item($this->em, $project, 4);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    private function rowCount(string $sql, string $id): int
    {
        return (int) $this->connection->fetchOne($sql, ['id' => $id]);
    }
}
