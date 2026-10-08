<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardChanged;
use App\Module\Bridge\Event\CardHeld;
use App\Module\Inbox\Command\AnswerInboxItemCommand;
use App\Module\Inbox\Command\AnswerInboxItemHandler;
use App\Module\Inbox\Command\DeclineInboxItemCommand;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Command\WithdrawInboxItemCommand;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxWorkflowAsk;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Mcp\InboxSubjectResolver;
use App\Module\Inbox\Repository\InboxWorkflowAskRepository;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\RuleAsks;
use App\Module\Workflow\Messenger\RunRuleAskAnswer;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** The inbox side of an ask: the engine opens it through the port, and the owner answers it once. */
final class InboxRuleAsksTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private InMemoryTransport $transport;
    private RuleAsks $asks;
    private Project $project;
    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;
        $asks = self::getContainer()->get(RuleAsks::class);
        self::assertInstanceOf(RuleAsks::class, $asks);
        $this->asks = $asks;

        $this->project = $this->project($em, $this->owner($em, 'rule-asks'), 'rule-asks');
        $this->card = $this->card($em, $this->project, 3);
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $em->clear();
        $this->transport->reset();
    }

    public function test_the_port_follows_the_inbox_flag(): void
    {
        self::assertTrue($this->asks->isOn($this->projectId()));

        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        self::assertFalse($this->asks->isOn($this->projectId()));
    }

    public function test_open_writes_a_blocking_single_choice_item_that_links_the_card(): void
    {
        $item = $this->open();

        self::assertSame(InboxItemKind::Workflow, $item->kind);
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertSame('Child 3 has no design', $item->title);
        self::assertSame(['Link', 'Design', 'Detach'], $item->options);
        self::assertTrue($item->blocking);
        self::assertFalse($item->multiple);
        self::assertFalse($item->freeText);
        self::assertSame(InboxAskOrigin::Loupe, $item->origin);
        self::assertSame([(string) $this->card->id], array_map(static fn ($link): string => (string) $link->card->id, $item->cards->toArray()));

        $inboxWorkflowAsks = self::getContainer()->get(InboxWorkflowAskRepository::class);
        self::assertInstanceOf(InboxWorkflowAskRepository::class, $inboxWorkflowAsks);
        $row = $inboxWorkflowAsks->findOneBy(['item' => $item]);
        self::assertInstanceOf(InboxWorkflowAsk::class, $row);
        self::assertSame((string) $this->card->id, (string) $row->cardId);
        self::assertSame('unplanned-child', $row->ruleId);
    }

    public function test_withdraw_closes_an_open_item_and_ignores_a_closed_one(): void
    {
        $item = $this->open();

        $this->asks->withdraw($item->id ?? self::fail(), 'The rule stopped holding.');
        $this->em->clear();
        $stored = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);
        self::assertSame(InboxItemState::Withdrawn, $stored->state);
        self::assertSame('The rule stopped holding.', $stored->closeNote);

        $this->asks->withdraw($item->id, 'Again');
        $this->em->clear();
        self::assertSame('The rule stopped holding.', $this->em->find(InboxItem::class, $item->id)?->closeNote);
    }

    public function test_the_answer_is_final_and_queues_the_picked_index(): void
    {
        $item = $this->open();

        $this->answer($item, '1');
        self::assertSame(InboxItemState::Answered, $item->state);
        self::assertSame([1], $item->selectedOptions);
        self::assertEquals([new RunRuleAskAnswer((string) $item->id, 1)], $this->answers());

        $this->transport->reset();
        $errors = $this->refused(fn () => $this->answer($item, '2'));
        self::assertSame(['selectedOptions' => InboxItemCloser::ERROR_FINAL], $errors);
        self::assertSame([], $this->answers());
        self::assertSame([1], $item->selectedOptions);
    }

    public function test_a_bad_answer_queues_nothing(): void
    {
        $item = $this->open();

        self::assertSame(['selectedOptions' => 'inbox.answer.error.unknown_option'], $this->refused(fn () => $this->answer($item, '3')));
        self::assertSame(['selectedOptions' => 'inbox.answer.error.one_option'], $this->refused(fn () => $this->answer($item, '0,1')));
        self::assertSame(['answerText' => 'inbox.answer.error.no_free_text'], $this->refused(fn () => $this->answer($item, '0', 'why')));
        self::assertSame(['selectedOptions' => 'inbox.answer.error.empty'], $this->refused(fn () => $this->answer($item, '')));
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertSame([], $this->answers());
    }

    public function test_decline_and_agent_withdraw_refuse_the_item(): void
    {
        $item = $this->open();

        $decline = self::getContainer()->get(DeclineInboxItemHandler::class);
        self::assertInstanceOf(DeclineInboxItemHandler::class, $decline);
        self::assertSame(['closeNote' => DeclineInboxItemHandler::WORKFLOW_NOT_DECLINABLE], $this->refused(fn () => $decline(new DeclineInboxItemCommand($item, ''))));

        $withdraw = self::getContainer()->get(WithdrawInboxItemHandler::class);
        self::assertInstanceOf(WithdrawInboxItemHandler::class, $withdraw);
        self::assertSame(['itemId' => WithdrawInboxItemHandler::WORKFLOW_NOT_WITHDRAWABLE], $this->refused(fn () => $withdraw(new WithdrawInboxItemCommand($item, 'No'))));

        self::assertSame(InboxItemState::Open, $item->state);
    }

    public function test_a_held_card_withdraws_its_open_items_only(): void
    {
        $item = $this->open();
        $answered = $this->open();
        $this->answer($answered, '0');
        $this->transport->reset();

        $this->dispatch(new CardHeld($this->projectId(), $this->cardId()));

        $this->em->clear();
        self::assertSame(InboxItemState::Withdrawn, $this->em->find(InboxItem::class, $item->id)?->state);
        self::assertSame(InboxItemState::Answered, $this->em->find(InboxItem::class, $answered->id)?->state);
    }

    public function test_a_deleted_card_withdraws_its_items_by_card_id(): void
    {
        $item = $this->open();

        $this->dispatch(new CardChanged($this->projectId(), $this->cardId(), CardChanged::UPDATED, false));
        $this->em->clear();
        self::assertSame(InboxItemState::Open, $this->em->find(InboxItem::class, $item->id)?->state);

        $this->dispatch(new CardChanged($this->projectId(), $this->cardId(), CardChanged::DELETED, false));
        $this->em->clear();
        self::assertSame(InboxItemState::Withdrawn, $this->em->find(InboxItem::class, $item->id)?->state);
    }

    public function test_an_agent_cannot_create_the_kind(): void
    {
        $resolver = self::getContainer()->get(InboxSubjectResolver::class);
        self::assertInstanceOf(InboxSubjectResolver::class, $resolver);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('~unknown kind "workflow"~');
        $resolver->requireAskItems([['kind' => 'workflow', 'title' => 'x']]);
    }

    private function open(): InboxItem
    {
        $id = $this->asks->open($this->projectId(), $this->cardId(), 'unplanned-child', 'Child 3 has no design', ['Link', 'Design', 'Detach']);
        $item = $this->em->find(InboxItem::class, $id);
        self::assertInstanceOf(InboxItem::class, $item);

        return $item;
    }

    private function answer(InboxItem $item, string $selected, string $text = ''): void
    {
        $handler = self::getContainer()->get(AnswerInboxItemHandler::class);
        self::assertInstanceOf(AnswerInboxItemHandler::class, $handler);
        $handler(new AnswerInboxItemCommand($item, $selected, $text));
    }

    /**
     * @return list<object>
     */
    private function answers(): array
    {
        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport->getSent()),
            static fn (object $message): bool => $message instanceof RunRuleAskAnswer,
        ));
    }

    /**
     * @param \Closure(): mixed $call
     *
     * @return array<string, string>
     */
    private function refused(\Closure $call): array
    {
        try {
            $call();
        } catch (DomainErrors $e) {
            return $e->errors;
        }
        self::fail('The handler accepted the response.');
    }

    private function dispatch(object $event): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch($event);
    }

    private function projectId(): Uuid
    {
        return $this->project->id ?? throw new \LogicException('Stored.');
    }

    private function cardId(): Uuid
    {
        return $this->card->id ?? throw new \LogicException('Stored.');
    }
}
