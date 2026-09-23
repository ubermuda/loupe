<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Service\LifecycleStages;
use App\Module\Board\Service\StageCard;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StageCard::class)]
final class StageCardTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = $this->createStub(Project::class);
    }

    public function test_it_names_the_card_in_the_stage_column(): void
    {
        $document = $this->documentTagged(['design', 'decisions']);
        $card = $this->card('tech-design', 4);

        self::assertSame($card, $this->stageCard($document, [$card]));
    }

    public function test_the_lowest_number_wins_when_two_cards_sit_in_the_stage_column(): void
    {
        $document = $this->documentTagged(['design', 'decisions']);
        $higher = $this->card('tech-design', 9);
        $lower = $this->card('tech-design', 3);

        self::assertSame($lower, $this->stageCard($document, [$higher, $lower]));
    }

    public function test_a_card_in_another_column_is_not_the_stage_card(): void
    {
        $document = $this->documentTagged(['design', 'decisions']);

        self::assertNull($this->stageCard($document, [$this->card('implementation', 1)]));
    }

    public function test_a_document_with_no_stage_tag_names_no_card(): void
    {
        $document = $this->documentTagged(['plan']);

        self::assertNull($this->stageCard($document, [$this->card('tech-design', 1)]));
    }

    public function test_a_document_tagged_for_both_stages_names_no_card(): void
    {
        $document = $this->documentTagged(['product', 'design', 'decisions']);

        self::assertNull($this->stageCard($document, [$this->card('tech-design', 1), $this->card('product-design', 2)]));
    }

    /** @param list<Card> $cards */
    private function stageCard(Document $document, array $cards): ?Card
    {
        $links = array_map(static fn (Card $card): CardDocument => new CardDocument($card, $document), $cards);

        return new StageCard(new LifecycleStages())->forDocument($document, $links);
    }

    private function card(string $column, int $number): Card
    {
        return new Card(
            project: $this->project,
            column: new BoardColumn(project: $this->project, label: $column, slug: $column, position: 0),
            title: 'Card '.$number,
            body: 'Body',
            number: $number,
        );
    }

    /** @param list<string> $names */
    private function documentTagged(array $names): Document
    {
        $document = new Document($this->createStub(User::class), $this->project, 'A document');
        foreach ($names as $name) {
            $document->tags->add(new Tag($this->project, $name));
        }

        return $document;
    }
}
