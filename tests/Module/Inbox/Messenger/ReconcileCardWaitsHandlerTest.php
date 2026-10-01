<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Messenger;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Board\Entity\CardDocument;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Messenger\ReconcileCardWaitsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
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
        $this->stageDocument($this->em, $document, $card);
        $this->em->flush();

        ($this->handler)(new ReconcileCardWaits((string) $project->id, [(string) $card->id]));

        self::assertSame(1, $this->openWaitItems((string) $project->id));
    }

    public function test_inside_an_unflushed_write_it_queues_the_message_and_writes_nothing(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'reconcile-inline'), 'reconcile-inline');
        $card = $this->card($this->em, $project);
        $document = $this->document($this->em, $project);
        $document->addVersion('# One', '<h1>One</h1>');
        $card->documents->add(new CardDocument($card, $document));
        $this->stageDocument($this->em, $document, $card);
        $this->em->flush();
        $pending = new Document($project->owner, $project, 'Not flushed yet');
        $this->em->persist($pending);
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $message = new ReconcileCardWaits((string) $project->id, [(string) $card->id]);

        ($this->handler)($message);

        self::assertSame(0, $this->openWaitItems((string) $project->id));
        self::assertSame([$message], array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()));
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($pending));
    }

    public function test_a_deleted_project_is_skipped(): void
    {
        $before = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items');

        ($this->handler)(new ReconcileCardWaits((string) Uuid::v7(), null));

        self::assertSame($before, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items'));
    }

    public function test_a_project_reconcile_opens_the_notice_of_a_rule_reported_before(): void
    {
        $project = $this->racingProject('reconcile-notice');

        ($this->handler)(new ReconcileCardWaits((string) $project->id, null));

        self::assertSame(['open'], $this->noticeStates((string) $project->id));
    }

    public function test_a_reconcile_of_some_cards_leaves_the_notice_alone(): void
    {
        $project = $this->racingProject('reconcile-notice-cards');

        ($this->handler)(new ReconcileCardWaits((string) $project->id, [(string) Uuid::v7()]));

        self::assertSame([], $this->noticeStates((string) $project->id));
    }

    public function test_a_project_reconcile_with_the_inbox_off_closes_the_notice_as_obsolete(): void
    {
        $project = $this->racingProject('reconcile-notice-off');
        ($this->handler)(new ReconcileCardWaits((string) $project->id, null));
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        ($this->handler)(new ReconcileCardWaits((string) $project->id, null));

        self::assertSame(['obsolete'], $this->noticeStates((string) $project->id));
    }

    /** A project whose sync is on and whose bridge reported a racing rule, with no event since. */
    private function racingProject(string $slug): Project
    {
        $project = $this->project($this->em, $this->owner($this->em, $slug), $slug);
        $settings = new BoardAutomationSettings($project);
        $settings->syncBehind = true;
        $this->em->persist($settings);
        $this->em->persist(new BridgeRuleReport($project, Uuid::v4(), [
            ['name' => 'sync-behind', 'on' => 'pull_request.behind', 'columns' => [], 'state' => BridgeRuleReport::STATE_LIVE, 'reason' => null],
        ]));
        $this->em->flush();

        return $project;
    }

    /** @return list<string> oldest first */
    private function noticeStates(string $projectId): array
    {
        return array_map(
            static fn (mixed $state): string => (string) $state,
            $this->em->getConnection()->fetchFirstColumn("SELECT state FROM inbox_items WHERE project_id = :id AND kind = 'notice' ORDER BY number", ['id' => $projectId]),
        );
    }

    private function openWaitItems(string $projectId): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM inbox_items WHERE project_id = :id AND kind = 'wait' AND state = 'open'", ['id' => $projectId]);
    }
}
