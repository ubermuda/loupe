<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\EventListener\DeleteInboxDataOnProjectDeleting;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteInboxDataOnProjectDeletingTest extends KernelTestCase
{
    use InboxFixtures;

    private const array TABLES = ['inbox_items', 'inbox_asks', 'inbox_ask_items', 'inbox_item_cards', 'inbox_item_documents'];

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

    /**
     * The listener runs alone here, so no cascade from a card, a document or
     * the project row can hide a listener that forgot a table.
     */
    public function test_the_listener_deletes_the_inbox_rows_of_its_project_only(): void
    {
        $owner = $this->owner($this->em, 'inbox-listener');
        [$doomed, $doomedRows] = $this->seedInbox($owner, 'doomed');
        [$spared, $sparedRows] = $this->seedInbox($owner, 'spared');
        $this->em->flush();
        $doomedIds = $this->ids($doomedRows);
        $sparedIds = $this->ids($sparedRows);

        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($doomedIds));

        $listener = self::getContainer()->get(DeleteInboxDataOnProjectDeleting::class);
        self::assertInstanceOf(DeleteInboxDataOnProjectDeleting::class, $listener);
        $listener(new ProjectDeleting($doomed));

        self::assertSame(array_fill_keys(self::TABLES, 0), $this->counts($doomedIds));
        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($sparedIds));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => (string) $doomed->id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => (string) $doomed->id]));
    }

    /**
     * Covers the listener order the container uses: Board, Review and Inbox
     * all delete rows the inbox links point at, and the project delete succeeds.
     */
    public function test_deleting_a_project_with_linked_inbox_rows_succeeds(): void
    {
        $owner = $this->owner($this->em, 'inbox-project-delete');
        [$doomed, $doomedRows] = $this->seedInbox($owner, 'doomed');
        [, $sparedRows] = $this->seedInbox($owner, 'spared');
        $this->em->flush();
        $doomedId = (string) $doomed->id;
        $doomedIds = $this->ids($doomedRows);
        $sparedIds = $this->ids($sparedRows);

        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($doomedIds));

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => $doomedId]));
        self::assertSame(array_fill_keys(self::TABLES, 0), $this->counts($doomedIds));
        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($sparedIds));
    }

    /**
     * @return array{Project, array<string, object>}
     */
    private function seedInbox(User $owner, string $name): array
    {
        $project = $this->project($this->em, $owner, $name);
        $card = $this->card($this->em, $project);
        $document = $this->document($this->em, $project);
        $item = $this->item($this->em, $project);
        $itemCard = new InboxItemCard($item, $card);
        $item->cards->add($itemCard);
        $itemDocument = new InboxItemDocument($item, $document);
        $item->documents->add($itemDocument);
        $ask = $this->ask($this->em, $project);
        $ask->card = $card;
        $askItem = new InboxAskItem($ask, $item);
        $ask->items->add($askItem);

        return [$project, [
            'inbox_items' => $item,
            'inbox_asks' => $ask,
            'inbox_ask_items' => $askItem,
            'inbox_item_cards' => $itemCard,
            'inbox_item_documents' => $itemDocument,
        ]];
    }

    /**
     * Read after the flush, so a count never joins through a parent row the
     * delete already removed.
     *
     * @param array<string, object> $rows
     *
     * @return array<string, string>
     */
    private function ids(array $rows): array
    {
        return array_map(static function (object $row): string {
            self::assertTrue(property_exists($row, 'id'));

            return (string) $row->id;
        }, $rows);
    }

    /**
     * @param array<string, string> $ids table name to row id
     *
     * @return array<string, int>
     */
    private function counts(array $ids): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = (int) $this->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE id = :id', $table), ['id' => $ids[$table]]);
        }

        return $counts;
    }
}
