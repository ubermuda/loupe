<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Module\Inbox\Command\UpdateInboxSettingsCommand;
use App\Module\Inbox\Command\UpdateInboxSettingsHandler;
use App\Module\Inbox\Entity\InboxProjectSettings;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Repository\InboxProjectSettingsRepository;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class UpdateInboxSettingsHandlerTest extends KernelTestCase
{
    use InboxFixtures;

    public function test_a_second_save_updates_the_stored_row_and_asks_for_the_whole_project(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $project = $this->project($em, $this->owner($em, 'inbox-settings-update'), 'inbox-settings-update');
        $stored = new InboxProjectSettings($project);
        $stored->documentInReview = false;
        $em->persist($stored);
        $em->flush();
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $handler = self::getContainer()->get(UpdateInboxSettingsHandler::class);
        self::assertInstanceOf(UpdateInboxSettingsHandler::class, $handler);
        $handler(new UpdateInboxSettingsCommand($project, documentInReview: true, runBlocked: false, runGaveUp: true, runWaitingForPerson: false, pullRequestReady: false, pullRequestFixStopped: true, cardPaused: false));

        $em->clear();
        $rows = self::getContainer()->get(InboxProjectSettingsRepository::class)->findBy(['project' => (string) $project->id]);
        self::assertCount(1, $rows);
        self::assertSame((string) $stored->id, (string) $rows[0]->id);
        self::assertTrue($rows[0]->documentInReview);
        self::assertFalse($rows[0]->runBlocked);
        self::assertTrue($rows[0]->runGaveUp);
        self::assertFalse($rows[0]->runWaitingForPerson);
        self::assertFalse($rows[0]->pullRequestReady);
        self::assertTrue($rows[0]->pullRequestFixStopped);
        self::assertFalse($rows[0]->cardPaused);
        self::assertEquals(
            [new ReconcileCardWaits((string) $project->id, null)],
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
        );
    }
}
