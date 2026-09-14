<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Command\AskInboxCommand;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\AskInboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxLinkResolver;
use App\Module\Inbox\Service\InboxOpenCountPublisher;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Inbox\Service\InboxSessionAsks;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditOutcome;

final class AskInboxHandlerTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
    }

    /**
     * A rival call from the same session commits its ask while this call waits
     * for the project lock. The lookup must run after the lock, or this call
     * misses the rival's ask and the one-open-ask index refuses its insert.
     */
    public function test_a_rival_ask_that_commits_while_the_call_waits_for_the_lock_is_extended(): void
    {
        $project = $this->persistedProject('ask-handler-rival-lock');
        $sessionId = Uuid::v4();
        $rivalAskId = Uuid::v7();

        $handler = $this->handler(new RivalAskOnLock($this->em, [
            'id' => (string) $rivalAskId,
            'project_id' => (string) $project->id,
            'session_id' => (string) $sessionId,
            'created_at' => '2026-09-14 00:00:00',
        ]));

        $view = $handler(new AskInboxCommand($project, $sessionId, [new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true)]));

        self::assertTrue($view->extended);
        self::assertSame((string) $rivalAskId, (string) $view->ask->id);
    }

    /** The project lock serialises one project. A session that asks in two at once meets the index instead. */
    public function test_a_rival_open_ask_in_another_project_is_refused_rather_than_fatal(): void
    {
        $project = $this->persistedProject('ask-handler-rival-here');
        $elsewhere = $this->persistedProject('ask-handler-rival-elsewhere');
        $sessionId = Uuid::v4();

        $handler = $this->handler(new RivalAskBeforeFlush($this->em, [
            'id' => (string) Uuid::v7(),
            'project_id' => (string) $elsewhere->id,
            'session_id' => (string) $sessionId,
            'created_at' => '2026-09-14 00:00:00',
        ]));

        try {
            $handler(new AskInboxCommand($project, $sessionId, [new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true)]));
            self::fail('Expected DomainErrors for a session whose open ask a concurrent call put in another project.');
        } catch (DomainErrors $e) {
            self::assertSame(['sessionId' => InboxSessionAsks::SESSION_ASK_ELSEWHERE], $e->errors);
        }
    }

    public function test_an_ask_is_recorded_without_the_text_the_agent_wrote(): void
    {
        $project = $this->persistedProject('ask-handler-audit');
        $sessionId = Uuid::v4();

        $view = $this->handler($this->em)(new AskInboxCommand($project, $sessionId, [
            new AskInboxItem(InboxItemKind::Question, 'A secret title', body: 'A secret body', freeText: true),
        ], context: 'A secret context'));

        $event = $this->audit->record('inbox.items_asked');
        self::assertSame(AuditOutcome::Success, $event->outcome);
        self::assertSame((string) $view->ask->id, $event->context['askId']);
        self::assertSame((string) $sessionId, $event->context['sessionId']);
        self::assertSame('1', $event->context['itemNumbers']);
        self::assertStringNotContainsString('secret', (string) json_encode($event->context));
    }

    private function persistedProject(string $slug): Project
    {
        $project = $this->project($this->em, $this->owner($this->em, $slug), $slug);
        $this->em->flush();

        return $project;
    }

    private function handler(EntityManagerInterface $em): AskInboxHandler
    {
        $container = self::getContainer();
        $items = $container->get(InboxItemRepository::class);
        self::assertInstanceOf(InboxItemRepository::class, $items);
        $sessionAsks = $container->get(InboxSessionAsks::class);
        self::assertInstanceOf(InboxSessionAsks::class, $sessionAsks);
        $links = $container->get(InboxLinkResolver::class);
        self::assertInstanceOf(InboxLinkResolver::class, $links);
        $indexer = $container->get(InboxSearchIndexer::class);
        self::assertInstanceOf(InboxSearchIndexer::class, $indexer);

        $openCount = $container->get(InboxOpenCountPublisher::class);
        self::assertInstanceOf(InboxOpenCountPublisher::class, $openCount);

        return new AskInboxHandler($items, $sessionAsks, $links, $indexer, $em, $this->audit->auditor, $openCount);
    }
}
