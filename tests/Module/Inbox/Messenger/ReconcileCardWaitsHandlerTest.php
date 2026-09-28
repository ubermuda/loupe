<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Messenger;

use App\Module\Board\Entity\CardDocument;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Messenger\ReconcileCardWaitsHandler;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ReconcileCardWaitsHandlerTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private ReconcileCardWaitsHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $handler = self::getContainer()->get(ReconcileCardWaitsHandler::class);
        self::assertInstanceOf(ReconcileCardWaitsHandler::class, $handler);
        $this->handler = $handler;
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_it_reconciles_the_cards_of_the_project(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'reconcile-message'), 'reconcile-message');
        $card = $this->card($this->em, $project);
        $document = $this->document($this->em, $project);
        $document->addVersion('# One', '<h1>One</h1>');
        $card->documents->add(new CardDocument($card, $document));
        $this->em->flush();

        ($this->handler)(new ReconcileCardWaits((string) $project->id, [(string) $card->id]));

        self::assertSame(1, $this->openWaitItems((string) $project->id));
    }

    public function test_a_deleted_project_is_skipped(): void
    {
        $before = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items');

        ($this->handler)(new ReconcileCardWaits((string) Uuid::v7(), null));

        self::assertSame($before, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items'));
    }

    private function openWaitItems(string $projectId): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM inbox_items WHERE project_id = :id AND kind = 'wait' AND state = 'open'", ['id' => $projectId]);
    }
}
