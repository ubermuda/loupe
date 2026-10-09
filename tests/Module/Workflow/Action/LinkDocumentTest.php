<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Action\ActionOutcome;
use App\Module\Workflow\Action\LinkDocument;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Template\ActionType;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LinkDocumentTest extends KernelTestCase
{
    use ActionScenario;

    /** @var array<string, Tag> */
    private array $tags = [];

    public function test_it_links_the_tagged_document_of_the_parent_whatever_its_status_and_keeps_the_documents_of_the_card(): void
    {
        self::bootKernel();
        [$card, $parent] = $this->childAndParent('link-doc');
        $own = $this->document($card, 'notes', DocumentStatus::Approved);
        $design = $this->document($parent, 'tech-design', DocumentStatus::InReview);

        $outcome = $this->link($card, $this->facts($card, $parent));

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertEqualsCanonicalizing([(string) $own->id, (string) $design->id], $this->linkedIds($card));
    }

    public function test_a_parent_without_the_tagged_document_is_a_refusal_and_links_nothing(): void
    {
        self::bootKernel();
        [$card, $parent] = $this->childAndParent('link-none');
        $this->document($parent, 'notes');

        $outcome = $this->link($card, $this->facts($card, $parent));

        self::assertEquals(ActionOutcome::refused('no-parent-document'), $outcome);
        self::assertSame([], $this->linkedIds($card));
    }

    public function test_a_document_the_card_links_already_is_done_and_changes_nothing(): void
    {
        self::bootKernel();
        [$card, $parent] = $this->childAndParent('link-twice');
        $design = $this->document($parent, 'tech-design');
        $this->em()->persist(new CardDocument($card, $design));
        $this->em()->flush();

        $outcome = $this->link($card, $this->facts($card, $parent));

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame([(string) $design->id], $this->linkedIds($card));
    }

    public function test_with_two_tagged_documents_it_links_the_one_linked_first(): void
    {
        self::bootKernel();
        [$card, $parent] = $this->childAndParent('link-first');
        $first = $this->document($parent, 'tech-design');
        $this->document($parent, 'tech-design');

        $this->link($card, $this->facts($card, $parent));

        self::assertSame([(string) $first->id], $this->linkedIds($card));
    }

    public function test_a_document_the_board_refuses_to_link_is_a_refusal(): void
    {
        self::bootKernel();
        [$card, $parent] = $this->childAndParent('link-refused');
        $facts = FactsMother::facts(card: FactsMother::card(isChild: true, parentDocuments: [new DocumentFacts(['tech-design'], 'approved', '01a10beb-ba65-736b-8626-a6e3fa59dfc5')]));

        $outcome = $this->link($card, $facts);

        self::assertEquals(ActionOutcome::refused('document-link-refused'), $outcome);
        self::assertSame([], $this->linkedIds($card));
    }

    /** @return array{Card, Card} */
    private function childAndParent(string $name): array
    {
        $project = $this->workflowProject($name);
        $parent = $this->card($project, 'next');
        $card = $this->card($project, 'next');
        $card->parent = $parent;
        $this->em()->flush();

        return [$card, $parent];
    }

    private function facts(Card $card, Card $parent): \App\Module\Workflow\Contract\Facts
    {
        $documents = static fn (Card $of): array => array_map(
            static fn (array $row): DocumentFacts => new DocumentFacts($row['tags'], $row['status'], $row['id']),
            self::getContainer()->get(CardDocumentRepository::class)->findStatusesAndTagsForCard($of),
        );

        return FactsMother::facts(card: FactsMother::card(isChild: true, documents: $documents($card), parentDocuments: $documents($parent)));
    }

    private function link(Card $card, \App\Module\Workflow\Contract\Facts $facts): ActionOutcome
    {
        // The card was built in this test, so its document collection has not read the rows that the test wrote.
        $this->em()->clear();
        $card = $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.');
        $rule = $this->rule(ActionType::LinkDocument, ['from' => 'parent', 'tag' => 'tech-design'], 'unplanned-child');

        return new LinkDocument($this->service(CardRepository::class), $this->service(UpdateCardHandler::class))->run($rule, $card->snapshot(), $facts, $this->state($card, $rule->id));
    }

    /** @return list<string> */
    private function linkedIds(Card $card): array
    {
        $this->em()->clear();

        return array_map(
            static fn (array $row): string => $row['id'],
            $this->service(CardDocumentRepository::class)->findStatusesAndTagsForCard($this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.')),
        );
    }

    private function document(Card $card, string $tagName, DocumentStatus $status = DocumentStatus::Approved): Document
    {
        $document = new Document($card->project->owner, $card->project, 'Design');
        $document->status = $status;
        $tag = $this->tags[$card->project->id.'/'.$tagName] ??= new Tag($card->project, $tagName);
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $document;
    }
}
