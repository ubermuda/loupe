<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Engine\EngineSwitch;
use App\Outbox\Entity\OutboxEvent;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ManagedCardMoveTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set(EngineSwitch::class, new EngineSwitch(true));
        $this->project = $this->workflowProject('managed-move');
        $this->bindLifecycle($this->project);
    }

    public function test_a_refused_move_leaves_the_card_where_it_was(): void
    {
        $card = $this->card('next');
        $cardId = $card->id;

        try {
            $this->updateCard()(new UpdateCardCommand(card: $card, actor: CardReporter::Human, column: $this->column($this->project, 'in-progress')));
            self::fail('A move the template does not list must be refused.');
        } catch (CardManaged $e) {
            self::assertSame($card->number, $e->cardNumber);
        }

        $this->em()->clear();
        $stored = $this->em()->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $stored);
        self::assertSame('next', $stored->column->slug);
    }

    public function test_a_manual_move_of_the_template_goes_through(): void
    {
        $card = $this->card('next');

        $this->updateCard()(new UpdateCardCommand(card: $card, actor: CardReporter::Human, column: $this->column($this->project, 'tech-design')));

        self::assertSame('tech-design', $card->column->slug);
    }

    public function test_an_edit_that_keeps_the_column_is_not_a_move(): void
    {
        $card = $this->card('in-progress');

        $this->updateCard()(new UpdateCardCommand(card: $card, actor: CardReporter::Human, title: 'Renamed', column: $card->column));

        self::assertSame('Renamed', $card->title);
    }

    public function test_a_move_that_holds_the_card_writes_the_hold_and_its_event_with_the_move(): void
    {
        $card = $this->card('next');

        $this->updateCard()(new UpdateCardCommand(card: $card, actor: CardReporter::Human, column: $this->column($this->project, 'in-progress'), unmanageBy: $this->project->owner));

        self::assertSame('in-progress', $card->column->slug);
        self::assertTrue($this->holds()->isHeld($this->project, $this->idOf($card)));
        self::assertCount(1, $this->heldEvents());
    }

    public function test_a_refused_move_that_would_hold_the_card_holds_nothing(): void
    {
        $card = $this->card('next');
        $notAnEpic = $this->card('next');

        try {
            $this->updateCard()(new UpdateCardCommand(
                card: $card,
                actor: CardReporter::Human,
                column: $this->column($this->project, 'in-progress'),
                parentCardId: (string) $notAnEpic->id,
                unmanageBy: $this->project->owner,
            ));
            self::fail('A card that is no epic cannot be a parent.');
        } catch (DomainErrors) {
        }

        self::assertFalse($this->holds()->isHeld($this->project, $this->idOf($card)));
        self::assertSame([], $this->heldEvents());
    }

    /** @return list<OutboxEvent> */
    private function heldEvents(): array
    {
        $outbox = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outbox);

        return array_values($outbox->findBy(['project' => $this->project->id, 'type' => BridgeEventType::CARD_HELD]));
    }

    private function holds(): CardHolds
    {
        $holds = self::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);

        return $holds;
    }

    private function idOf(Card $card): Uuid
    {
        return $card->id ?? throw new \LogicException('A created card has an id.');
    }

    private function updateCard(): UpdateCardHandler
    {
        $updateCard = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $updateCard);

        return $updateCard;
    }

    private function card(string $column): Card
    {
        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);

        return $create(new CreateCardCommand(
            project: $this->project,
            title: 'Card',
            body: 'Body',
            type: CardType::Feature,
            column: $this->column($this->project, $column),
            reporter: CardReporter::Human,
        ));
    }
}
