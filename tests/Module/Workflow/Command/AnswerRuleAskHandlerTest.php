<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Command\AnswerRuleAskCommand;
use App\Module\Workflow\Command\AnswerRuleAskHandler;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class AnswerRuleAskHandlerTest extends KernelTestCase
{
    use ActionScenario;

    private RecordingAuditor $audit;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
    }

    public function test_the_link_option_links_the_parent_design_keeps_the_item_id_and_queues_an_evaluation(): void
    {
        [$card, $parent, $itemId] = $this->askedChild('answer-link');
        $design = $this->document($parent, 'tech-design');

        $this->answer($itemId, 0);

        self::assertSame([(string) $design->id], $this->linkedIds($card));
        $state = $this->state_($card);
        self::assertEquals($itemId, $state->askItemId);
        self::assertNull($state->lastRefusal);
        self::assertContains($card->id?->toRfc4122(), $this->queuedEvaluations());
        self::assertContains('workflow.ask_answered', array_column($this->audit->sink->events, 'operation'));
    }

    public function test_the_detach_option_removes_the_parent(): void
    {
        [$card, , $itemId] = $this->askedChild('answer-detach');

        $this->answer($itemId, 1);

        $this->em()->clear();
        self::assertNull($this->em()->find(Card::class, $card->id)?->parent);
        self::assertContains($card->id?->toRfc4122(), $this->queuedEvaluations());
    }

    public function test_the_options_actions_run_in_order(): void
    {
        [$card, $parent, $itemId] = $this->askedChild('answer-order');
        $design = $this->document($parent, 'tech-design');

        $this->answer($itemId, 2);

        $this->em()->clear();
        $reloaded = $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.');
        self::assertSame('in-progress', $reloaded->column->slug);
        self::assertSame([(string) $design->id], $this->linkedIds($card));
    }

    public function test_a_refusal_stops_the_option_and_stays_on_the_rule_state(): void
    {
        [$card, , $itemId] = $this->askedChild('answer-refused');

        $this->answer($itemId, 2);

        $this->em()->clear();
        $reloaded = $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.');
        self::assertSame('next', $reloaded->column->slug);
        $state = $this->state_($card);
        self::assertSame('no-parent-document', $state->lastRefusal);
        self::assertNotNull($state->lastRefusalAt);
        self::assertEquals($itemId, $state->askItemId);
        self::assertContains($card->id?->toRfc4122(), $this->queuedEvaluations());
        self::assertContains('workflow.ask_answered', array_column($this->audit->sink->events, 'operation'));
    }

    public function test_an_item_that_no_rule_state_holds_changes_nothing(): void
    {
        [$card, $parent] = $this->askedChild('answer-unknown');
        $this->document($parent, 'tech-design');

        $this->answer(Uuid::v7(), 0);

        self::assertSame([], $this->linkedIds($card));
        self::assertSame([], $this->queuedEvaluations());
    }

    public function test_an_item_the_engine_withdrew_changes_nothing(): void
    {
        [$card, $parent, $itemId] = $this->askedChild('answer-withdrawn');
        $this->document($parent, 'tech-design');
        $this->state_($card)->askItemId = null;
        $this->em()->flush();

        $this->answer($itemId, 0);

        self::assertSame([], $this->linkedIds($card));
        self::assertSame([], $this->queuedEvaluations());
    }

    public function test_an_option_the_rule_lacks_changes_nothing(): void
    {
        [$card, $parent, $itemId] = $this->askedChild('answer-range');
        $this->document($parent, 'tech-design');

        $this->answer($itemId, 7);

        self::assertSame([], $this->linkedIds($card));
        self::assertSame([], $this->queuedEvaluations());
    }

    public function test_a_held_card_changes_nothing(): void
    {
        [$card, $parent, $itemId] = $this->askedChild('answer-held');
        $this->document($parent, 'tech-design');
        $this->service(CardHolds::class)->hold($card->project, $card->id ?? throw new \LogicException('A flushed card has an id.'), null);

        $this->answer($itemId, 0);

        self::assertSame([], $this->linkedIds($card));
        self::assertSame([], $this->queuedEvaluations());
    }

    private function answer(Uuid $itemId, int $optionIndex): void
    {
        $this->em()->clear();
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        ($this->service(AnswerRuleAskHandler::class))(new AnswerRuleAskCommand($itemId->toRfc4122(), $optionIndex));
    }

    /** @return array{Card, Card, Uuid} the child, its parent and the item id that the child's rule state holds */
    private function askedChild(string $name): array
    {
        $project = $this->workflowProject($name);
        $this->bind($project);
        $parent = $this->card($project, 'next');
        $card = $this->card($project, 'next');
        $card->parent = $parent;
        $itemId = Uuid::v7();
        $state = new WorkflowRuleState($card, $project, 'unplanned-child');
        $state->truth = true;
        $state->askItemId = $itemId;
        $this->em()->persist($state);
        $this->em()->flush();

        return [$card, $parent, $itemId];
    }

    private function bind(Project $project): void
    {
        $this->em()->persist(new WorkflowBinding($project, 'test', 1, [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime']],
            'slots' => [['key' => 'one', 'label' => 'one'], ['key' => 'two', 'label' => 'two']],
            'manualMoves' => [],
            'backoffMinutes' => [],
            'workTimeoutMinutes' => 120,
            'rules' => [[
                'id' => 'unplanned-child',
                'slot' => 'one',
                'when' => ['all' => []],
                'then' => ['ask' => [
                    'question' => 'Question',
                    'options' => [
                        ['label' => 'Link', 'then' => [['link-document' => ['from' => 'parent', 'tag' => 'tech-design']]]],
                        ['label' => 'Detach', 'then' => [['detach' => []]]],
                        ['label' => 'Link and move', 'then' => [['link-document' => ['from' => 'parent', 'tag' => 'tech-design']], ['move' => ['to' => 'two']]]],
                    ],
                ]],
            ]],
        ]));
        $this->em()->persist(new WorkflowSlotLink($project, 'one', $this->column($project, 'next')));
        $this->em()->persist(new WorkflowSlotLink($project, 'two', $this->column($project, 'in-progress')));
        $this->em()->flush();
    }

    private function state_(Card $card): WorkflowRuleState
    {
        $this->em()->clear();

        return $this->service(WorkflowRuleStateRepository::class)->findOneBy(['card' => $card->id]) ?? throw new \LogicException('The rule state exists.');
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

    /** @return list<string> */
    private function queuedEvaluations(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $ids = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard) {
                $ids[] = $message->cardId;
            }
        }

        return $ids;
    }

    private function document(Card $card, string $tagName): Document
    {
        $document = new Document($card->project->owner, $card->project, 'Design');
        $document->status = DocumentStatus::InReview;
        $tag = new Tag($card->project, $tagName);
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $document;
    }
}
