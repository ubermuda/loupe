<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Event\SiteReviewCommentStatusChanged;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Tests\Module\Board\CardVerdictScenario;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class EvaluateCardOnSiteReviewCommentStatusChangedTest extends KernelTestCase
{
    use BoardToolScenario;
    use CardVerdictScenario;

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('note-status-evaluation');
    }

    public function test_a_status_change_evaluates_the_card_of_the_note(): void
    {
        $card = $this->card($this->project);
        $note = $this->note($card, 'Footer overlaps');
        $this->em->flush();

        $this->dispatch($note->id ?? throw new \LogicException('A stored note has an id.'));

        self::assertEquals([new EvaluateCard((string) $card->id)], $this->evaluations());
    }

    public function test_a_note_with_no_card_evaluates_nothing(): void
    {
        $this->dispatch(Uuid::v7());

        self::assertSame([], $this->evaluations());
    }

    private function dispatch(Uuid $commentId): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new SiteReviewCommentStatusChanged($this->project->id ?? throw new \LogicException('A stored project has an id.'), $commentId));
    }

    /** @return list<EvaluateCard> */
    private function evaluations(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof EvaluateCard,
        ));
    }
}
