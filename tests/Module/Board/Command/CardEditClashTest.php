<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Event\CardChanged;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * An edit form carries the fingerprint of the text it opened with. The handler
 * compares it, under the project lock, with the text the database holds, so a
 * change another writer committed first is refused unless the editor confirms.
 */
final class CardEditClashTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private UpdateCardHandler $updateCard;
    private Card $card;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $updateCard = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $updateCard);
        $this->updateCard = $updateCard;
        $owner = new User(fullName: 'Riley', email: 'board-clash-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();

        $this->card = $this->createCard('Opened body');
    }

    /** CreateCardHandler stores the body as given, the way an MCP tool passes it. */
    private function createCard(string $body): Card
    {
        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);

        return $createCard(new CreateCardCommand(
            project: $this->project,
            title: 'Opened title',
            body: $body,
            type: CardType::Feature,
            column: $this->column($this->project, 'backlog'),
        ));
    }

    public function test_the_fingerprint_covers_the_title_and_the_body_apart(): void
    {
        self::assertSame(hash('sha256', "a\0b"), Card::contentFingerprint('a', 'b'));
        self::assertNotSame(Card::contentFingerprint('ab', ''), Card::contentFingerprint('a', 'b'));
    }

    public function test_the_fingerprint_reads_the_text_as_the_web_form_submits_it(): void
    {
        self::assertSame(Card::contentFingerprint('a', 'b'), Card::contentFingerprint(" a\n", "\r\nb\r\n"));
        self::assertSame(Card::contentFingerprint('a', "b\nc"), Card::contentFingerprint('a', "b\r\nc"));
        self::assertSame(Card::contentFingerprint('a', "b\nc"), Card::contentFingerprint('a', "b\rc"));
        self::assertNotSame(Card::contentFingerprint('a', "b\nc"), Card::contentFingerprint('a', 'b c'));
    }

    public function test_a_web_save_of_an_agent_body_is_no_clash_and_no_content_change(): void
    {
        $this->card = $this->createCard("Opened body\n");
        $openedByEditorA = Card::contentFingerprint($this->card->title, $this->card->body);
        $contentChanged = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardChanged::class, static function (CardChanged $event) use (&$contentChanged): void {
            $contentChanged[] = $event->contentChanged;
        });

        // Editor B changes the type alone. The form trims the textarea.
        ($this->updateCard)(new UpdateCardCommand(
            card: $this->card,
            actor: CardReporter::Human,
            title: 'Opened title',
            body: 'Opened body',
            type: CardType::Bug,
            expectedFingerprint: Card::contentFingerprint('Opened title', "Opened body\n"),
        ));
        // Editor A saves the same text with the fingerprint it opened with.
        ($this->updateCard)(new UpdateCardCommand(
            card: $this->card,
            actor: CardReporter::Human,
            title: 'Opened title',
            body: "Opened body\r\n",
            type: CardType::Docs,
            expectedFingerprint: $openedByEditorA,
        ));

        self::assertSame([false, false], $contentChanged);
        self::assertSame(CardType::Docs, $this->card->type);
    }

    public function test_a_real_change_to_an_agent_body_still_clashes(): void
    {
        $this->card = $this->createCard("Opened body\n");
        $this->em->getConnection()->executeStatement(
            "UPDATE board_cards SET body = 'Opened body, edited' WHERE id = :id",
            ['id' => (string) $this->card->id],
        );

        try {
            ($this->updateCard)(new UpdateCardCommand(
                card: $this->card,
                actor: CardReporter::Human,
                body: 'Opened body',
                expectedFingerprint: Card::contentFingerprint('Opened title', "Opened body\n"),
            ));
            self::fail('The clash was not refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['contentFingerprint' => UpdateCardHandler::CONTENT_CHANGED], $e->errors);
        }
    }

    public function test_an_update_with_no_fingerprint_checks_nothing(): void
    {
        $this->changeBehindTheEditor();

        ($this->updateCard)(new UpdateCardCommand(card: $this->card, actor: CardReporter::Agent, body: 'Agent body'));

        self::assertSame(['Other title', 'Agent body'], $this->stored());
    }

    public function test_an_update_with_the_fingerprint_the_database_holds_saves(): void
    {
        ($this->updateCard)(new UpdateCardCommand(
            card: $this->card,
            actor: CardReporter::Human,
            title: 'Opened title',
            body: 'Edited body',
            expectedFingerprint: Card::contentFingerprint('Opened title', 'Opened body'),
        ));

        self::assertSame(['Opened title', 'Edited body'], $this->stored());
    }

    public function test_an_update_over_a_change_it_did_not_see_is_refused(): void
    {
        $this->changeBehindTheEditor();

        try {
            ($this->updateCard)(new UpdateCardCommand(
                card: $this->card,
                actor: CardReporter::Human,
                title: 'Opened title',
                body: 'Edited body',
                expectedFingerprint: Card::contentFingerprint('Opened title', 'Opened body'),
            ));
            self::fail('The clash was not refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['contentFingerprint' => UpdateCardHandler::CONTENT_CHANGED], $e->errors);
        }

        self::assertSame(['Other title', 'Other body'], $this->stored());
    }

    public function test_a_confirmed_update_overwrites_a_change_it_did_not_see(): void
    {
        $this->changeBehindTheEditor();

        ($this->updateCard)(new UpdateCardCommand(
            card: $this->card,
            actor: CardReporter::Human,
            title: 'Opened title',
            body: 'Edited body',
            expectedFingerprint: Card::contentFingerprint('Opened title', 'Opened body'),
            confirmOverwrite: true,
        ));

        self::assertSame(['Opened title', 'Edited body'], $this->stored());
    }

    /** Another writer commits on the test's own connection, so the loaded card keeps the old text. */
    private function changeBehindTheEditor(): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE board_cards SET title = 'Other title', body = 'Other body' WHERE id = :id",
            ['id' => (string) $this->card->id],
        );
        self::assertSame('Opened title', $this->card->title);
    }

    /** @return list<string> the title and body the database holds */
    private function stored(): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT title, body FROM board_cards WHERE id = :id',
            ['id' => (string) $this->card->id],
        );
        self::assertIsArray($row);

        return [(string) $row['title'], (string) $row['body']];
    }
}
