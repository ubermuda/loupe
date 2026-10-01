<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Messenger\SyncNextPullRequest;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SyncBehindOnPullRequestStateChangedTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private InMemoryTransport $transport;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;

        $this->enableBoard();
        $this->project = $this->makeProject('sync-behind-listener');
    }

    public function test_a_state_change_queues_one_pass_for_the_project(): void
    {
        $this->settings(enabled: true, syncBehind: true);

        $this->dispatchChange();

        $messages = $this->syncMessages();
        self::assertCount(1, $messages);
        self::assertEquals($this->project->id, $messages[0]->projectId);
    }

    public function test_nothing_queues_while_the_setting_is_off(): void
    {
        $this->settings(enabled: true, syncBehind: false);

        $this->dispatchChange();

        self::assertSame([], $this->syncMessages());
    }

    public function test_nothing_queues_while_the_automation_is_off(): void
    {
        $this->settings(enabled: false, syncBehind: true);

        $this->dispatchChange();

        self::assertSame([], $this->syncMessages());
    }

    public function test_nothing_queues_for_a_project_that_saved_no_settings(): void
    {
        $this->dispatchChange();

        self::assertSame([], $this->syncMessages());
    }

    public function test_nothing_queues_while_the_board_is_off(): void
    {
        $this->settings(enabled: true, syncBehind: true);
        $this->disableBoard();

        $this->dispatchChange();

        self::assertSame([], $this->syncMessages());
    }

    private function settings(bool $enabled, bool $syncBehind): void
    {
        $settings = new BoardAutomationSettings($this->project, enabled: $enabled);
        $settings->syncBehind = $syncBehind;
        $this->em->persist($settings);
        $this->em->flush();
    }

    /** Forge dispatches the event inside the transaction that stores the new state. */
    private function dispatchChange(): void
    {
        $pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->em->persist($pullRequest);
        $this->em->flush();
        $event = new PullRequestStateChanged(
            $pullRequest,
            new PullRequestSnapshot(headSha: 'abc1234'),
            new PullRequestSnapshot(headSha: 'abc1234', mergeability: PullRequestMergeability::Behind),
        );

        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $this->em->wrapInTransaction(static fn (): object => $events->dispatch($event));
    }

    /** @return list<SyncNextPullRequest> */
    private function syncMessages(): array
    {
        $messages = [];
        foreach ($this->transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SyncNextPullRequest) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
