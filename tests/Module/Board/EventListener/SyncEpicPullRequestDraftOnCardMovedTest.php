<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\CardType;
use App\Module\Board\Messenger\SyncEpicPullRequestDraft;
use App\Tests\Module\Board\EpicPullRequestScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SyncEpicPullRequestDraftOnCardMovedTest extends KernelTestCase
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

    public function test_an_epic_with_a_pull_request_that_enters_review_queues_a_sync_on_the_async_transport(): void
    {
        $epic = $this->card($this->reviewProject('draft-review'), 'implementation');
        $this->linkPullRequest($epic, 7);

        $this->moveTo($epic, 'in-review');

        $sent = $this->sent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SyncEpicPullRequestDraft::class, $message);
        self::assertEquals($epic->id, $message->cardId);
        self::assertSame(['async'], $sent[0]->last(TransportNamesStamp::class)?->getTransportNames());
    }

    public function test_an_epic_with_a_pull_request_that_enters_implementation_queues_a_sync(): void
    {
        $epic = $this->card($this->reviewProject('draft-implementation'), 'in-review');
        $this->linkPullRequest($epic, 7);

        $this->moveTo($epic, 'implementation');

        self::assertCount(1, $this->sent());
    }

    public function test_a_feature_queues_nothing(): void
    {
        $card = $this->card($this->reviewProject('draft-feature'), 'implementation', CardType::Feature);
        $this->linkPullRequest($card, 7);

        $this->moveTo($card, 'in-review');

        self::assertSame([], $this->sent());
    }

    public function test_an_epic_with_no_pull_request_queues_nothing(): void
    {
        $epic = $this->card($this->reviewProject('draft-no-link'), 'implementation');

        $this->moveTo($epic, 'in-review');

        self::assertSame([], $this->sent());
    }

    public function test_an_epic_that_enters_another_column_queues_nothing(): void
    {
        $epic = $this->card($this->reviewProject('draft-other'), 'implementation');
        $this->linkPullRequest($epic, 7);

        $this->moveTo($epic, 'next');

        self::assertSame([], $this->sent());
    }

    public function test_a_reorder_inside_the_review_column_queues_nothing(): void
    {
        $epic = $this->card($this->reviewProject('draft-reorder'), 'in-review');
        $this->linkPullRequest($epic, 7);

        $this->moveTo($epic, 'in-review', position: 0);

        self::assertSame([], $this->sent());
    }

    public function test_nothing_is_queued_while_the_board_is_off(): void
    {
        $epic = $this->card($this->reviewProject('draft-board-off'), 'implementation');
        $this->linkPullRequest($epic, 7);
        $this->disableBoard();

        $this->moveTo($epic, 'in-review');

        self::assertSame([], $this->sent());
    }

    /** @return list<Envelope> */
    private function sent(): array
    {
        return array_values(array_filter(
            $this->transport->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof SyncEpicPullRequestDraft,
        ));
    }
}
