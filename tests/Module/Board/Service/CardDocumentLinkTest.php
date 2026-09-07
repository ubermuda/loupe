<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\ProjectDeleter;
use App\Module\Review\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardDocumentLinkTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private UpdateCardHandler $updateCard;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $this->createCard = $create;
        $update = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $this->updateCard = $update;
    }

    public function test_a_card_links_the_documents_its_work_is_written_up_in(): void
    {
        [$project, $doc] = $this->projectWithDocument('link-ok');

        $card = ($this->createCard)($this->newCard($project, [(string) $doc->id]));

        self::assertSame(
            [(string) $doc->id],
            array_map(
                static fn ($link): string => (string) $link->document->id,
                array_values($card->documents->toArray()),
            ),
        );
    }

    /**
     * The check that matters. Without it a card could name a document of
     * somebody else's project, and the card page would render its title.
     */
    public function test_a_document_of_another_project_is_refused(): void
    {
        [$project] = $this->projectWithDocument('link-mine');
        [, $theirs] = $this->projectWithDocument('link-theirs');

        $this->expectException(DomainErrors::class);
        ($this->createCard)($this->newCard($project, [(string) $theirs->id]));
    }

    public function test_an_id_naming_no_document_is_refused_rather_than_kept(): void
    {
        [$project, $doc] = $this->projectWithDocument('link-unknown');

        // Guard: a real id still works, so the refusals below are about the id
        // rather than about links being refused wholesale.
        self::assertCount(1, ($this->createCard)($this->newCard($project, [(string) $doc->id]))->documents);

        // The same project each time, which is the point: the refusal happens
        // before the handler opens a transaction, so nothing is rolled back and
        // the caller can carry on.
        foreach (['not-a-uuid', '0199c0de-0000-7000-8000-0000000000ff'] as $id) {
            try {
                ($this->createCard)($this->newCard($project, [$id]));
                self::fail(\sprintf('Expected "%s" to be refused.', $id));
            } catch (DomainErrors $e) {
                self::assertArrayHasKey('documentIds', $e->errors);
            }
        }
    }

    public function test_an_omitted_list_keeps_the_links_and_an_empty_one_clears_them(): void
    {
        [$project, $doc] = $this->projectWithDocument('link-replace');
        $card = ($this->createCard)($this->newCard($project, [(string) $doc->id]));

        ($this->updateCard)(new UpdateCardCommand($card, title: 'Renamed, links untouched'));
        self::assertCount(1, $card->documents);

        ($this->updateCard)(new UpdateCardCommand($card, documentIds: []));
        self::assertCount(0, $card->documents);
    }

    public function test_resubmitting_the_same_document_is_not_a_conflict(): void
    {
        // Doctrine issues inserts before orphan-removal deletes, so clearing
        // the collection and rebuilding it puts a second row with the same
        // (card, document) pair on the wire before the first is gone. The
        // unique index then refuses it. CardPullRequest has no such index,
        // which is why the shape this was copied from does not hit it.
        [$project, $doc] = $this->projectWithDocument('link-resubmit');
        $second = new Document($project->owner, $project, 'Another design');
        $this->em->persist($second);
        $this->em->flush();

        $card = ($this->createCard)($this->newCard($project, [(string) $doc->id]));

        ($this->updateCard)(new UpdateCardCommand($card, documentIds: [(string) $doc->id]));
        self::assertCount(1, $card->documents);

        ($this->updateCard)(new UpdateCardCommand(
            $card,
            documentIds: [(string) $doc->id, (string) $second->id],
        ));
        self::assertSame(
            [(string) $doc->id, (string) $second->id],
            array_map(
                static fn ($link): string => (string) $link->document->id,
                array_values($card->documents->toArray()),
            ),
        );
    }

    public function test_deleting_the_document_takes_the_link_and_leaves_the_card(): void
    {
        [$project, $doc] = $this->projectWithDocument('link-cascade');
        $card = ($this->createCard)($this->newCard($project, [(string) $doc->id]));
        $cardId = $card->id;

        // Review knows nothing about the link, so the database cascade is what
        // keeps the row from outliving the document.
        $this->em->remove($doc);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(Card::class, $cardId);
        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->documents);
    }

    public function test_deleting_the_project_removes_the_links(): void
    {
        [$project, $doc] = $this->projectWithDocument('link-project-delete');
        ($this->createCard)($this->newCard($project, [(string) $doc->id]));

        $conn = $this->em->getConnection();
        // Guard: without it the absence below also passes on a fixture that
        // never wrote a link.
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_card_documents'));

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($project);

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_card_documents'));
    }

    /** @param list<string> $documentIds */
    private function newCard(Project $project, array $documentIds): CreateCardCommand
    {
        return new CreateCardCommand(
            project: $project,
            title: 'Write the design up',
            body: 'body',
            type: CardType::Feature,
            priority: CardPriority::Medium,
            documentIds: $documentIds,
        );
    }

    /** @return array{Project, Document} */
    private function projectWithDocument(string $slug): array
    {
        $owner = new \App\Module\Account\Entity\User(
            fullName: 'Riley',
            email: $slug.'-'.uniqid().'@example.com',
            password: 'hashed',
        );
        $this->em->persist($owner);
        $project = new Project($owner, $slug.'-'.uniqid());
        $this->em->persist($project);
        $document = new Document($owner, $project, 'The design');
        $this->em->persist($document);
        $this->em->flush();

        return [$project, $document];
    }
}
