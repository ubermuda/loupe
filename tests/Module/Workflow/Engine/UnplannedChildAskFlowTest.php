<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Inbox\Command\AnswerInboxItemCommand;
use App\Module\Inbox\Command\AnswerInboxItemHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Messenger\RunRuleAskAnswer;
use App\Module\Workflow\Messenger\RunRuleAskAnswerHandler;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/** The shipped rule, the real inbox and the real answer handler, from an unplanned child to its detach. */
final class UnplannedChildAskFlowTest extends KernelTestCase
{
    use ActionScenario;

    public function test_the_engine_asks_the_owner_and_the_answer_runs_the_picked_option(): void
    {
        $child = $this->unplannedChild(true);

        $this->service(Engine::class)->evaluate($child->id ?? throw new \LogicException('Flushed.'), new \DateTimeImmutable());

        $this->em()->clear();
        $state = $this->service(WorkflowRuleStateRepository::class)->findOneBy(['card' => $child->id, 'ruleId' => 'unplanned-child']);
        self::assertNotNull($state?->askItemId);
        $item = $this->em()->find(InboxItem::class, $state->askItemId) ?? throw new \LogicException('The item exists.');
        self::assertSame(InboxItemKind::Workflow, $item->kind);
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertCount(3, $item->options);
        self::assertStringContainsString('#'.$child->number, $item->title);

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $this->service(AnswerInboxItemHandler::class)(new AnswerInboxItemCommand($item, '2', ''));
        $messages = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof RunRuleAskAnswer,
        ));
        self::assertCount(1, $messages);
        self::assertSame(2, $messages[0]->optionIndex);

        $this->service(RunRuleAskAnswerHandler::class)($messages[0]);

        $this->em()->clear();
        self::assertNull($this->em()->find(Card::class, $child->id)?->parent);
    }

    private function unplannedChild(bool $inboxOn): Card
    {
        self::bootKernel();
        $this->inbox($inboxOn);
        $project = $this->workflowProject('unplanned-flow');
        $this->bindLifecycle($project);
        $epic = $this->card($project, 'tech-design');
        $child = $this->card($project, 'next');
        $child->parent = $epic;
        $design = new Document($project->owner, $project, 'Design');
        $design->status = DocumentStatus::Approved;
        $tag = new Tag($project, 'tech-design');
        $this->em()->persist($tag);
        $design->tags->add($tag);
        $this->em()->persist($design);
        $this->em()->persist(new CardDocument($epic, $design));
        $this->em()->flush();

        return $child;
    }

    public function test_with_the_inbox_off_the_child_pauses_and_no_item_opens(): void
    {
        $child = $this->unplannedChild(false);

        $this->service(Engine::class)->evaluate($child->id ?? throw new \LogicException('Flushed.'), new \DateTimeImmutable());

        $this->em()->clear();
        self::assertCount(1, $this->service(CardPauseRepository::class)->findActiveForCardIds([$child->id]));
        self::assertSame([], $this->service(InboxItemRepository::class)->findAll());
    }

    private function inbox(bool $enabled): void
    {
        $flags = $this->service(FeatureFlagRepository::class);
        $flags->findAllIndexed()[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = $enabled;
        $this->em()->flush();
        $reader = $this->service(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }
}
