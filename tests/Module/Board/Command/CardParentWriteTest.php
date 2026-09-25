<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Event\CardParentChanged;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class CardParentWriteTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private UpdateCardHandler $updateCard;
    private DeleteCardHandler $deleteCard;
    private RecordingAuditor $audit;

    /** @var list<CardParentChanged> */
    private array $parentEvents = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $this->createCard = $create;

        $update = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $this->updateCard = $update;

        $delete = self::getContainer()->get(DeleteCardHandler::class);
        self::assertInstanceOf(DeleteCardHandler::class, $delete);
        $this->deleteCard = $delete;

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardParentChanged::class, function (CardParentChanged $event): void {
            $this->parentEvents[] = $event;
        });
    }

    public function test_a_card_created_under_an_epic_carries_it(): void
    {
        $project = $this->makeProject('parent-create');
        $epic = $this->cardIn($project, CardType::Epic);
        $this->parentEvents = [];

        $child = ($this->createCard)(new CreateCardCommand($project, 'Child', '', CardType::Feature, parentCardId: (string) $epic->id));
        $this->em->clear();

        self::assertSame((string) $epic->id, (string) $this->reload($child)->parent?->id);
        $events = $this->parentEvents();
        self::assertCount(1, $events);
        self::assertNull($events[0]->oldParent);
        self::assertSame((string) $epic->id, (string) $events[0]->newParent?->id);
        self::assertSame(CardReporter::Agent, $events[0]->actor);
    }

    public function test_a_card_created_with_no_parent_fires_no_event_and_keeps_its_lane_setting(): void
    {
        $project = $this->makeProject('parent-create-none');
        $this->parentEvents = [];

        $epic = ($this->createCard)(new CreateCardCommand($project, 'Epic', '', CardType::Epic, laneEnabled: false));
        $default = $this->cardIn($project, CardType::Epic);
        $this->em->clear();

        self::assertNull($this->reload($epic)->parent);
        self::assertFalse($this->reload($epic)->laneEnabled);
        self::assertTrue($this->reload($default)->laneEnabled);
        self::assertSame([], $this->parentEvents);
    }

    public function test_a_create_with_a_bad_parent_is_refused_and_writes_nothing(): void
    {
        $project = $this->makeProject('parent-create-refused');
        $epic = $this->cardIn($project, CardType::Epic);
        $feature = $this->cardIn($project);
        $theirs = $this->cardIn($this->makeProject('parent-create-theirs'), CardType::Epic);
        $this->em->clear();

        $refusals = [
            'board.card.error.parent_unknown' => [CardType::Feature, (string) $theirs->id],
            'board.card.error.parent_not_epic' => [CardType::Feature, (string) $feature->id],
            'board.card.error.epic_cannot_have_parent' => [CardType::Epic, (string) $epic->id],
        ];
        foreach ($refusals as $key => [$type, $parentId]) {
            try {
                ($this->createCard)(new CreateCardCommand($this->reloadProject($project), 'Refused', '', $type, parentCardId: $parentId));
                self::fail(\sprintf('Expected the refusal %s.', $key));
            } catch (DomainErrors $e) {
                self::assertSame(['parent' => $key], $e->errors);
            }
            self::assertTrue($this->em->isOpen());
        }

        self::assertSame(0, $this->countTitled('Refused'));
    }

    public function test_an_update_sets_keeps_and_clears_the_parent(): void
    {
        $project = $this->makeProject('parent-update');
        $epic = $this->cardIn($project, CardType::Epic);
        $card = $this->cardIn($project);
        $this->em->clear();
        $this->audit->forget();
        $this->parentEvents = [];

        $this->update($card, parentCardId: (string) $epic->id);
        $this->em->clear();
        self::assertSame((string) $epic->id, (string) $this->reload($card)->parent?->id);
        self::assertTrue($this->audit->record('board.card_updated')->context['parentChanged']);
        self::assertCount(1, $this->parentEvents);

        // Null keeps the parent, and the same parent again is no change.
        $this->audit->forget();
        $this->update($card, title: 'Renamed');
        $this->update($card, parentCardId: (string) $epic->id);
        $this->em->clear();
        self::assertSame((string) $epic->id, (string) $this->reload($card)->parent?->id);
        self::assertFalse($this->audit->record('board.card_updated')->context['parentChanged']);
        self::assertCount(1, $this->parentEvents);

        // An empty string clears it.
        $this->update($card, parentCardId: '');
        $this->em->clear();
        self::assertNull($this->reload($card)->parent);
        self::assertCount(2, $this->parentEvents);
        self::assertSame((string) $epic->id, (string) $this->parentEvents[1]->oldParent?->id);
        self::assertNull($this->parentEvents[1]->newParent);
    }

    public function test_an_update_with_a_bad_parent_is_refused_and_changes_nothing(): void
    {
        $project = $this->makeProject('parent-update-refused');
        $epic = $this->cardIn($project, CardType::Epic);
        $otherEpic = $this->cardIn($project, CardType::Epic);
        $feature = $this->cardIn($project);
        $card = $this->cardIn($project);
        $theirs = $this->cardIn($this->makeProject('parent-update-theirs'), CardType::Epic);
        $this->em->clear();

        $refusals = [
            ['board.card.error.parent_unknown', $card, (string) $theirs->id],
            ['board.card.error.parent_unknown', $card, 'not-a-card-id'],
            ['board.card.error.parent_not_epic', $card, (string) $feature->id],
            // A card is never its own parent: the card is not an epic.
            ['board.card.error.parent_not_epic', $card, (string) $card->id],
            ['board.card.error.epic_cannot_have_parent', $otherEpic, (string) $epic->id],
        ];
        foreach ($refusals as [$key, $target, $parentId]) {
            try {
                $this->update($target, parentCardId: $parentId, title: 'Refused');
                self::fail(\sprintf('Expected the refusal %s.', $key));
            } catch (DomainErrors $e) {
                self::assertSame(['parent' => $key], $e->errors);
            }
            self::assertTrue($this->em->isOpen());
            $this->em->clear();
            self::assertNull($this->reload($target)->parent);
        }

        self::assertSame(0, $this->countTitled('Refused'));
    }

    public function test_an_epic_is_never_its_own_parent(): void
    {
        $project = $this->makeProject('parent-self-epic');
        $epic = $this->cardIn($project, CardType::Epic);
        $this->em->clear();

        $this->expectRefusal(['parent' => 'board.card.error.epic_cannot_have_parent'], fn () => $this->update($epic, parentCardId: (string) $epic->id));
    }

    public function test_a_card_with_a_parent_cannot_become_an_epic(): void
    {
        $project = $this->makeProject('parent-child-to-epic');
        $epic = $this->cardIn($project, CardType::Epic);
        $child = $this->cardIn($project, parent: $epic);
        $this->em->clear();

        $this->expectRefusal(['type' => 'board.card.error.parent_card_cannot_be_epic'], fn () => $this->update($child, type: CardType::Epic));
        // The same parent sent again with the type is still the kept parent.
        $this->expectRefusal(['type' => 'board.card.error.parent_card_cannot_be_epic'], fn () => $this->update($child, parentCardId: (string) $epic->id, type: CardType::Epic));
        $this->em->clear();
        self::assertSame(CardType::Feature, $this->reload($child)->type);

        // Clearing the parent in the same update lets it become an epic.
        $this->update($child, parentCardId: '', type: CardType::Epic);
        $this->em->clear();
        self::assertSame(CardType::Epic, $this->reload($child)->type);
        self::assertNull($this->reload($child)->parent);
    }

    public function test_an_epic_with_children_keeps_its_type_and_an_empty_one_does_not(): void
    {
        $project = $this->makeProject('parent-type-locked');
        $epic = $this->cardIn($project, CardType::Epic);
        $empty = $this->cardIn($project, CardType::Epic);
        $this->cardIn($project, parent: $epic);
        $this->em->clear();

        $this->expectRefusal(['type' => 'board.card.error.epic_type_locked'], fn () => $this->update($epic, type: CardType::Feature));
        $this->em->clear();
        self::assertSame(CardType::Epic, $this->reload($epic)->type);

        $this->update($empty, type: CardType::Feature);
        $this->em->clear();
        self::assertSame(CardType::Feature, $this->reload($empty)->type);
    }

    public function test_the_parent_type_is_read_again_under_the_lock(): void
    {
        $project = $this->makeProject('parent-stale-type');
        $epic = $this->cardIn($project, CardType::Epic);
        $card = $this->cardIn($project);
        $this->em->clear();
        $loaded = $this->reload($card);
        // The resolver finds the epic in the identity map as an epic.
        $this->reload($epic);

        // Another request turns the epic into a feature after this one loaded it.
        $this->em->getConnection()->executeStatement("UPDATE board_cards SET type = 'feature' WHERE id = ?", [(string) $epic->id]);

        $this->expectRefusal(
            ['parent' => 'board.card.error.parent_not_epic'],
            fn () => ($this->updateCard)(new UpdateCardCommand($loaded, CardReporter::Agent, parentCardId: (string) $epic->id)),
        );
    }

    public function test_the_card_parent_is_read_again_under_the_lock(): void
    {
        $project = $this->makeProject('parent-stale-own');
        $epic = $this->cardIn($project, CardType::Epic);
        $card = $this->cardIn($project);
        $this->em->clear();
        $loaded = $this->reload($card);

        // Another request gives the card a parent after this one loaded it.
        $this->em->getConnection()->executeStatement('UPDATE board_cards SET parent_card_id = ? WHERE id = ?', [(string) $epic->id, (string) $card->id]);

        $this->expectRefusal(
            ['type' => 'board.card.error.parent_card_cannot_be_epic'],
            fn () => ($this->updateCard)(new UpdateCardCommand($loaded, CardReporter::Agent, type: CardType::Epic)),
        );
    }

    public function test_an_update_records_a_lane_change(): void
    {
        $project = $this->makeProject('parent-lane');
        $epic = $this->cardIn($project, CardType::Epic);
        $this->em->clear();
        $this->audit->forget();

        $this->update($epic, laneEnabled: false);
        $this->em->clear();

        self::assertFalse($this->reload($epic)->laneEnabled);
        $context = $this->audit->record('board.card_updated')->context;
        self::assertTrue($context['laneChanged']);
        self::assertFalse($context['parentChanged']);
    }

    public function test_an_epic_with_children_is_deleted_only_once_they_leave_it(): void
    {
        $project = $this->makeProject('parent-delete');
        $epic = $this->cardIn($project, CardType::Epic);
        $first = $this->cardIn($project, parent: $epic);
        $second = $this->cardIn($project, parent: $epic);
        $this->em->clear();

        $this->expectRefusal(['card' => 'board.card.error.epic_delete_has_children'], fn () => ($this->deleteCard)(new DeleteCardCommand($this->reload($epic), CardReporter::Human)));
        $this->em->clear();
        self::assertNotNull($this->em->find(Card::class, $epic->id));

        $this->update($first, parentCardId: '');
        $this->update($second, parentCardId: '');
        $this->em->clear();

        ($this->deleteCard)(new DeleteCardCommand($this->reload($epic), CardReporter::Human));
        $this->em->clear();
        self::assertNull($this->em->find(Card::class, $epic->id));
    }

    public function test_a_child_is_deleted_with_no_refusal(): void
    {
        $project = $this->makeProject('parent-delete-child');
        $epic = $this->cardIn($project, CardType::Epic);
        $child = $this->cardIn($project, parent: $epic);
        $this->em->clear();

        ($this->deleteCard)(new DeleteCardCommand($this->reload($child), CardReporter::Human));
        $this->em->clear();

        self::assertNull($this->em->find(Card::class, $child->id));
        self::assertNotNull($this->em->find(Card::class, $epic->id));
    }

    /** @return list<CardParentChanged> */
    private function parentEvents(): array
    {
        return $this->parentEvents;
    }

    /** @param non-empty-array<string, string> $errors */
    private function expectRefusal(array $errors, callable $write): void
    {
        try {
            $write();
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }
        self::assertTrue($this->em->isOpen());
    }

    private function update(
        Card $card,
        ?string $parentCardId = null,
        ?string $title = null,
        ?CardType $type = null,
        ?bool $laneEnabled = null,
    ): void {
        ($this->updateCard)(new UpdateCardCommand(
            $this->reload($card),
            CardReporter::Agent,
            title: $title,
            type: $type,
            parentCardId: $parentCardId,
            laneEnabled: $laneEnabled,
        ));
    }

    private function cardIn(Project $project, CardType $type = CardType::Feature, ?Card $parent = null): Card
    {
        return ($this->createCard)(new CreateCardCommand(
            $this->reloadProject($project),
            'Ship it',
            'Body',
            $type,
            parentCardId: null === $parent ? null : (string) $parent->id,
        ));
    }

    private function reload(Card $card): Card
    {
        return $this->em->find(Card::class, $card->id) ?? throw new \LogicException('The card must exist.');
    }

    private function reloadProject(Project $project): Project
    {
        return $this->em->find(Project::class, $project->id) ?? throw new \LogicException('The project must exist.');
    }

    private function countTitled(string $title): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_cards WHERE title = ?', [$title]);
    }
}
