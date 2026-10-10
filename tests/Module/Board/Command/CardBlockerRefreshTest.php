<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Event\CardBlockersChanged;
use App\Module\Board\Event\CardChanged;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** A card that gains a blocker, or whose open blocker is renamed, is told so, because its Waiting mark reads the blocker. */
final class CardBlockerRefreshTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private Project $project;

    /** @var list<string> the ids of the cards each CardBlockersChanged names */
    private array $blockersChanged = [];

    /** @var list<string> the ids of the cards a CardChanged names */
    private array $changed = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Riley', email: 'board-blocker-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $em->persist($this->project);
        $this->seedColumns($this->project);
        $em->flush();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardBlockersChanged::class, function (CardBlockersChanged $event): void {
            foreach ($event->cards as $card) {
                $this->blockersChanged[] = (string) $card->id;
            }
        });
        $dispatcher->addListener(CardChanged::class, function (CardChanged $event): void {
            $this->changed[] = (string) $event->cardId;
        });
    }

    public function test_a_card_that_gains_a_blocker_on_an_update_is_named(): void
    {
        $blocker = $this->card('Blocker');
        $blocked = $this->card('Blocked');
        $this->blockersChanged = [];

        $this->update(new UpdateCardCommand(card: $blocker, actor: Actor::Agent, relatedCards: [new CardLinkInput((string) $blocked->id, CardLinkKind::Blocks)]));

        self::assertSame([(string) $blocked->id], $this->blockersChanged);
    }

    public function test_a_card_that_gains_a_blocker_on_a_create_is_named(): void
    {
        $blocked = $this->card('Blocked');
        $this->blockersChanged = [];

        $this->card('Blocker', [new CardLinkInput((string) $blocked->id, CardLinkKind::Blocks)]);

        self::assertSame([(string) $blocked->id], $this->blockersChanged);
    }

    public function test_a_related_link_names_no_card(): void
    {
        $other = $this->card('Other');
        $card = $this->card('Card');
        $this->blockersChanged = [];

        $this->update(new UpdateCardCommand(card: $card, actor: Actor::Agent, relatedCards: [new CardLinkInput((string) $other->id)]));

        self::assertSame([], $this->blockersChanged);
    }

    public function test_renaming_an_open_blocker_redraws_the_cards_it_blocks(): void
    {
        $blocked = $this->card('Blocked');
        $blocker = $this->card('Blocker', [new CardLinkInput((string) $blocked->id, CardLinkKind::Blocks)]);
        $this->changed = [];

        $this->update(new UpdateCardCommand(card: $blocker, actor: Actor::Agent, title: 'Blocker, renamed'));

        self::assertSame([(string) $blocker->id, (string) $blocked->id], $this->changed);
    }

    /** @param list<CardLinkInput> $relatedCards */
    private function card(string $title, array $relatedCards = []): Card
    {
        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);

        return $createCard(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: '',
            type: 'feature',
            column: $this->column($this->project, 'next'),
            relatedCards: $relatedCards,
        ));
    }

    private function update(UpdateCardCommand $command): void
    {
        $updateCard = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $updateCard);
        $updateCard($command);
    }
}
