<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Command\AnswerInboxItemCommand;
use App\Module\Inbox\Command\AnswerInboxItemHandler;
use App\Module\Inbox\Command\DeclineInboxItemCommand;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Command\MarkInboxItemDoneCommand;
use App\Module\Inbox\Command\MarkInboxItemDoneHandler;
use App\Module\Inbox\Command\WithdrawInboxItemCommand;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Loupe closes a notice when its fact ends, so no person and no agent responds to it. */
final class NoticeItemResponsesTest extends KernelTestCase
{
    use InboxFixtures;

    private InboxItem $notice;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $project = $this->project($em, $this->owner($em, 'notice-responses'), 'notice-responses');
        $this->notice = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Notice, title: 'A bridge rule races the app sync', blocking: false, body: 'Remove it.');
        $em->persist($this->notice);
        $em->flush();
    }

    public function test_answer_refuses_a_notice(): void
    {
        $handler = self::getContainer()->get(AnswerInboxItemHandler::class);
        self::assertInstanceOf(AnswerInboxItemHandler::class, $handler);

        self::assertSame(['selectedOptions' => 'inbox.answer.error.not_a_question'], $this->refusal(fn () => $handler(new AnswerInboxItemCommand($this->notice, '', 'Yes'))));
        self::assertSame(InboxItemState::Open, $this->notice->state);
    }

    public function test_mark_done_refuses_a_notice(): void
    {
        $handler = self::getContainer()->get(MarkInboxItemDoneHandler::class);
        self::assertInstanceOf(MarkInboxItemDoneHandler::class, $handler);

        self::assertSame(['item' => 'inbox.done.error.not_a_todo'], $this->refusal(fn () => $handler(new MarkInboxItemDoneCommand($this->notice))));
        self::assertSame(InboxItemState::Open, $this->notice->state);
    }

    public function test_decline_refuses_a_notice(): void
    {
        $handler = self::getContainer()->get(DeclineInboxItemHandler::class);
        self::assertInstanceOf(DeclineInboxItemHandler::class, $handler);

        self::assertSame(['closeNote' => InboxItemCloser::ERROR_NOTICE], $this->refusal(fn () => $handler(new DeclineInboxItemCommand($this->notice, ''))));
        self::assertSame(InboxItemState::Open, $this->notice->state);
    }

    public function test_withdraw_refuses_a_notice(): void
    {
        $handler = self::getContainer()->get(WithdrawInboxItemHandler::class);
        self::assertInstanceOf(WithdrawInboxItemHandler::class, $handler);

        self::assertSame(['itemId' => WithdrawInboxItemHandler::NOTICE_NOT_WITHDRAWABLE], $this->refusal(fn () => $handler(new WithdrawInboxItemCommand($this->notice, 'Not needed'))));
        self::assertSame(InboxItemState::Open, $this->notice->state);
    }

    /**
     * @param \Closure(): mixed $call
     *
     * @return array<string, string>
     */
    private function refusal(\Closure $call): array
    {
        try {
            $call();
        } catch (DomainErrors $e) {
            return $e->errors;
        }
        self::fail('The handler accepted the response.');
    }
}
