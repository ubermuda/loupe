<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\EventListener;

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
     * The listener runs alone here, so no cascade from a card or a document
     * delete can hide a listener that forgot a table.
     */
    public function test_the_listener_deletes_the_inbox_rows_of_its_project_only(): void
    {
        $owner = $this->owner($this->em, 'inbox-listener');
        $doomed = $this->seedInbox($owner, 'doomed');
        $spared = $this->seedInbox($owner, 'spared');
        $this->em->flush();

        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($doomed));

        $listener = self::getContainer()->get(DeleteInboxDataOnProjectDeleting::class);
        self::assertInstanceOf(DeleteInboxDataOnProjectDeleting::class, $listener);
        $listener(new ProjectDeleting($doomed));

        self::assertSame(array_fill_keys(self::TABLES, 0), $this->counts($doomed));
        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($spared));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => (string) $doomed->id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => (string) $doomed->id]));
    }

    /**
     * The listeners of Board, Review and Inbox run in no fixed order. This
     * proves that no order leaves a foreign key that refuses the project delete.
     */
    public function test_deleting_a_project_with_linked_inbox_rows_succeeds(): void
    {
        $owner = $this->owner($this->em, 'inbox-project-delete');
        $doomed = $this->seedInbox($owner, 'doomed');
        $spared = $this->seedInbox($owner, 'spared');
        $this->em->flush();
        $doomedId = (string) $doomed->id;

        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($doomed));

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => $doomedId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_items WHERE project_id = :id', ['id' => $doomedId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_asks WHERE project_id = :id', ['id' => $doomedId]));
        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($spared));
    }

    private function seedInbox(\App\Module\Account\Entity\User $owner, string $name): Project
    {
        $project = $this->project($this->em, $owner, $name);
        $card = $this->card($this->em, $project);
        $document = $this->document($this->em, $project);
        $item = $this->item($this->em, $project);
        $item->cards->add(new InboxItemCard($item, $card));
        $item->documents->add(new InboxItemDocument($item, $document));
        $ask = $this->ask($this->em, $project);
        $ask->card = $card;
        $ask->items->add(new InboxAskItem($ask, $item));

        return $project;
    }

    /** @return array<string, int> */
    private function counts(Project $project): array
    {
        $id = ['id' => (string) $project->id];

        return [
            'inbox_items' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_items WHERE project_id = :id', $id),
            'inbox_asks' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_asks WHERE project_id = :id', $id),
            'inbox_ask_items' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_ask_items l JOIN inbox_asks a ON a.id = l.ask_id WHERE a.project_id = :id', $id),
            'inbox_item_cards' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_item_cards l JOIN inbox_items i ON i.id = l.item_id WHERE i.project_id = :id', $id),
            'inbox_item_documents' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_item_documents l JOIN inbox_items i ON i.id = l.item_id WHERE i.project_id = :id', $id),
        ];
    }
}
