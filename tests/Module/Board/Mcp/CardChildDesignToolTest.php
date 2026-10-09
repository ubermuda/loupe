<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardUpdateTool;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardChildDesignToolTest extends KernelTestCase
{
    use McpTokenScenario;
    use WorkflowProjects;

    private Project $project;
    private Card $epic;
    private Document $design;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('child-design');
        $this->bindLifecycle($this->project);
        $this->actAsMcpTokenBoundTo($this->project);
        $this->epic = $this->card('Epic', 'epic');
        $this->design = $this->documentOf($this->epic, DocumentStatus::Approved);
    }

    public function test_inherit_links_the_design_of_the_parent_and_the_card_lands_in_the_column_asked_for(): void
    {
        $result = $this->create()(title: 'Fix', body: 'Body', type: 'feature', parentCardId: (string) $this->epic->id, childDesign: 'inherit');

        self::assertSame('backlog', $result['status']);
        self::assertSame([(string) $this->design->id], $this->linkedDocumentIds($result['cardId']));
    }

    public function test_own_moves_the_card_to_tech_design_and_links_nothing(): void
    {
        $result = $this->create()(title: 'Open question', body: 'Body', type: 'feature', parentCardId: (string) $this->epic->id, childDesign: 'own');

        self::assertSame('tech-design', $result['status']);
        self::assertSame([], $this->linkedDocumentIds($result['cardId']));
    }

    public function test_a_card_with_no_choice_under_an_approved_design_is_refused_and_not_created(): void
    {
        try {
            $this->create()(title: 'No choice', body: 'Body', type: 'feature', parentCardId: (string) $this->epic->id);
            self::fail('The call needs a choice.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('"inherit"', $e->getMessage());
            self::assertStringContainsString('"own"', $e->getMessage());
        }
        self::assertSame(0, $this->childCount('No choice'));
    }

    public function test_a_card_that_links_the_approved_design_itself_needs_no_choice(): void
    {
        $result = $this->create()(title: 'Linked', body: 'Body', type: 'feature', parentCardId: (string) $this->epic->id, documentIds: [(string) $this->design->id]);

        self::assertSame([(string) $this->design->id], $this->linkedDocumentIds($result['cardId']));
    }

    public function test_a_card_under_an_epic_with_no_approved_design_needs_no_choice(): void
    {
        $this->design->status = DocumentStatus::InReview;
        $this->em()->flush();

        $result = $this->create()(title: 'Free', body: 'Body', type: 'feature', parentCardId: (string) $this->epic->id);

        self::assertSame('backlog', $result['status']);
    }

    public function test_inherit_is_refused_when_the_parent_has_no_design_and_creates_nothing(): void
    {
        $bare = $this->card('Bare epic', 'epic');

        $this->assertRefused('no tech design to inherit', fn () => $this->create()(title: 'Nothing to inherit', body: 'Body', type: 'feature', parentCardId: (string) $bare->id, childDesign: 'inherit'));
        self::assertSame(0, $this->childCount('Nothing to inherit'));
    }

    public function test_a_choice_with_no_parent_is_refused(): void
    {
        $this->assertRefused('no parent', fn () => $this->create()(title: 'Orphan', body: 'Body', type: 'feature', childDesign: 'own'));
    }

    public function test_own_is_refused_before_the_write_when_tech_design_has_no_column(): void
    {
        $link = self::getContainer()->get(WorkflowSlotLinkRepository::class)->findOneBy(['project' => $this->project, 'slotKey' => 'tech-design']);
        self::assertNotNull($link);
        $link->columnId = null;
        $this->em()->flush();

        $this->assertRefused('no column for the Tech design step', fn () => $this->create()(title: 'No column', body: 'Body', type: 'feature', parentCardId: (string) $this->epic->id, childDesign: 'own'));
        self::assertSame(0, $this->childCount('No column'));
    }

    public function test_own_with_a_status_is_refused(): void
    {
        $this->assertRefused('pass no status', fn () => $this->create()(title: 'Both', body: 'Body', type: 'feature', status: 'next', parentCardId: (string) $this->epic->id, childDesign: 'own'));
        self::assertSame(0, $this->childCount('Both'));
    }

    public function test_an_unknown_choice_is_refused(): void
    {
        $this->assertRefused('"inherit" or "own"', fn () => $this->create()(title: 'Odd', body: 'Body', type: 'feature', parentCardId: (string) $this->epic->id, childDesign: 'both'));
    }

    public function test_update_sets_the_parent_and_inherits_in_one_call(): void
    {
        $card = $this->card('Loose', 'feature');

        $this->update()(cardId: (string) $card->id, parentCardId: (string) $this->epic->id, childDesign: 'inherit');

        self::assertSame([(string) $this->design->id], $this->linkedDocumentIds((string) $card->id));
    }

    public function test_update_that_resends_the_parent_it_has_needs_no_choice(): void
    {
        $card = $this->card('Kept child', 'feature', $this->epic);

        $result = $this->update()(cardId: (string) $card->id, title: 'Kept child, renamed', parentCardId: (string) $this->epic->id);

        self::assertSame('Kept child, renamed', $result['title']);
    }

    public function test_update_that_sets_a_parent_needs_the_choice(): void
    {
        $card = $this->card('Loose', 'feature');

        $this->assertRefused('"inherit"', fn () => $this->update()(cardId: (string) $card->id, parentCardId: (string) $this->epic->id));
    }

    public function test_update_with_own_moves_a_child_that_sits_in_next(): void
    {
        $child = $this->card('Child', 'feature', $this->epic, 'next');

        $result = $this->update()(cardId: (string) $child->id, childDesign: 'own');

        self::assertSame('tech-design', $result['status']);
    }

    public function test_update_with_own_on_a_card_the_workflow_will_not_move_is_refused_and_leaves_the_card(): void
    {
        $child = $this->card('In review', 'feature', $this->epic, 'in-review');

        $this->assertRefused('managed', fn () => $this->update()(cardId: (string) $child->id, childDesign: 'own'));
        self::assertSame('in-review', $this->em()->find(Card::class, $child->id)?->column->slug);
    }

    public function test_a_refused_own_leaves_the_other_fields_of_the_call_unwritten(): void
    {
        $child = $this->card('In review', 'feature', $this->epic, 'in-review');

        $this->assertRefused('managed', fn () => $this->update()(cardId: (string) $child->id, title: 'Renamed', childDesign: 'own'));
        $this->em()->clear();
        self::assertSame('In review', $this->em()->find(Card::class, $child->id)?->title);
    }

    private function assertRefused(string $fragment, callable $call): void
    {
        try {
            $call();
            self::fail('The call should be refused.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString($fragment, $e->getMessage());
        }
    }

    private function create(): CardCreateTool
    {
        return self::getContainer()->get(CardCreateTool::class);
    }

    private function update(): CardUpdateTool
    {
        return self::getContainer()->get(CardUpdateTool::class);
    }

    private function card(string $title, string $type, ?Card $parent = null, string $slug = 'next'): Card
    {
        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);

        return $create(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: 'Body',
            type: $type,
            column: $this->column($this->project, $slug),
            reporter: Actor::Human,
            parentCardId: null === $parent ? null : (string) $parent->id,
        ));
    }

    private function documentOf(Card $card, DocumentStatus $status): Document
    {
        $document = new Document($this->project->owner, $this->project, 'Design');
        $document->status = $status;
        $tag = new Tag($this->project, 'tech-design');
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $document;
    }

    /** @return list<string> */
    private function linkedDocumentIds(string $cardId): array
    {
        $this->em()->clear();
        $card = $this->em()->find(Card::class, $cardId) ?? throw new \LogicException('The card exists.');

        return array_map(
            static fn (array $row): string => $row['id'],
            self::getContainer()->get(\App\Module\Board\Repository\CardDocumentRepository::class)->findStatusesAndTagsForCard($card),
        );
    }

    private function childCount(string $title): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM board_cards WHERE title = ?', [$title]);
    }
}
