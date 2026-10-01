<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Messenger\CloseEpicPullRequests;
use App\Tests\Module\Board\EpicPullRequestScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CloseEpicPullRequestsOnCardMovedTest extends KernelTestCase
{
    use EpicPullRequestScenario;

    private EntityManagerInterface $em;
    private InMemoryTransport $transport;

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
    }

    public function test_an_epic_with_a_pull_request_moved_to_the_backlog_queues_a_close_on_the_async_transport(): void
    {
        $epic = $this->card($this->reviewProject('close-backlog'), 'implementation');
        $this->linkPullRequest($epic, 7);

        $this->moveTo($epic, 'backlog');

        $sent = $this->sent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(CloseEpicPullRequests::class, $message);
        self::assertEquals($epic->id, $message->cardId);
        self::assertSame(['async'], $sent[0]->last(TransportNamesStamp::class)?->getTransportNames());
    }

    public function test_an_agent_move_to_the_backlog_queues_a_close(): void
    {
        $epic = $this->card($this->reviewProject('close-agent'), 'implementation');
        $this->linkPullRequest($epic, 7);

        $this->moveTo($epic, 'backlog', CardReporter::Agent);

        self::assertCount(1, $this->sent());
    }

    public function test_a_system_move_to_the_backlog_queues_nothing(): void
    {
        $epic = $this->card($this->reviewProject('close-system'), 'implementation');
        $this->linkPullRequest($epic, 7);

        $this->moveTo($epic, 'backlog', CardReporter::System);

        self::assertSame([], $this->sent());
    }

    public function test_a_feature_queues_nothing(): void
    {
        $card = $this->card($this->reviewProject('close-feature'), 'implementation', CardType::Feature);
        $this->linkPullRequest($card, 7);

        $this->moveTo($card, 'backlog');

        self::assertSame([], $this->sent());
    }

    public function test_an_epic_with_no_pull_request_queues_nothing(): void
    {
        $epic = $this->card($this->reviewProject('close-no-link'), 'implementation');

        $this->moveTo($epic, 'backlog');

        self::assertSame([], $this->sent());
    }

    public function test_a_move_to_another_column_queues_nothing(): void
    {
        $epic = $this->card($this->reviewProject('close-other'), 'implementation');
        $this->linkPullRequest($epic, 7);

        $this->moveTo($epic, 'next');

        self::assertSame([], $this->sent());
    }

    public function test_a_reorder_inside_the_backlog_queues_nothing(): void
    {
        $epic = $this->card($this->reviewProject('close-reorder'), 'backlog');
        $this->linkPullRequest($epic, 7);

        $this->moveTo($epic, 'backlog', position: 0);

        self::assertSame([], $this->sent());
    }

    public function test_nothing_is_queued_while_the_board_is_off(): void
    {
        $epic = $this->card($this->reviewProject('close-board-off'), 'implementation');
        $this->linkPullRequest($epic, 7);
        $this->disableBoard();

        $this->moveTo($epic, 'backlog');

        self::assertSame([], $this->sent());
    }

    /** @return list<Envelope> */
    private function sent(): array
    {
        return array_values(array_filter(
            $this->transport->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof CloseEpicPullRequests,
        ));
    }
}
