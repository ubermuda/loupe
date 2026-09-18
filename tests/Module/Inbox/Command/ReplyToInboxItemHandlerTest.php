<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Command\ReplyToInboxItemCommand;
use App\Module\Inbox\Command\ReplyToInboxItemHandler;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxReplyRepository;
use App\Module\Inbox\Service\InboxReplyExporter;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ReplyToInboxItemHandlerTest extends KernelTestCase
{
    use InboxFixtures;

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_replies_append_once_without_changing_the_answer_or_resuming_an_agent(bool $closed): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'reply');
        $project = $this->project($em, $owner, 'reply');
        $item = $this->item($em, $project);
        $item->state = $closed ? InboxItemState::Answered : InboxItemState::Open;
        $item->answerText = $closed ? 'Keep this answer.' : null;
        $ask = $this->ask($em, $project);
        $ask->closedAt = $closed ? new \DateTimeImmutable('2026-09-01 12:00:00') : null;
        $originalClosedAt = $ask->closedAt;
        $em->persist(new InboxAskItem($ask, $item));
        $em->flush();
        $handler = self::getContainer()->get(ReplyToInboxItemHandler::class);
        self::assertInstanceOf(ReplyToInboxItemHandler::class, $handler);
        $command = new ReplyToInboxItemCommand($item, $owner, '  Add this context.  ', (string) Uuid::v4());
        $first = $handler($command);
        $replayed = $handler($command);
        self::assertSame((string) $first->id, (string) $replayed->id);
        $second = $handler(new ReplyToInboxItemCommand($item, $owner, 'Another thought.', (string) Uuid::v4()));
        $em->clear();
        $repository = self::getContainer()->get(InboxReplyRepository::class);
        self::assertInstanceOf(InboxReplyRepository::class, $repository);
        $stored = $em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);
        $replies = $repository->findForItem($stored);
        self::assertSame(['Add this context.', 'Another thought.'], array_map(static fn ($reply) => $reply->body, $replies));
        self::assertSame((string) $second->id, (string) $replies[1]->id);
        self::assertSame($closed ? InboxItemState::Answered : InboxItemState::Open, $stored->state);
        self::assertSame($closed ? 'Keep this answer.' : null, $stored->answerText);
        $storedAsk = $em->find(InboxAsk::class, $ask->id);
        self::assertInstanceOf(InboxAsk::class, $storedAsk);
        self::assertEquals($originalClosedAt, $storedAsk->closedAt);
        self::assertCount(1, $storedAsk->items);
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM outbox_events WHERE project_id = ?', [(string) $project->id]));

        $exporter = self::getContainer()->get(InboxReplyExporter::class);
        self::assertInstanceOf(InboxReplyExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($owner), false);
        self::assertCount(2, $rows);
        self::assertSame('Add this context.', $rows[0]['body']);
        self::assertSame((string) $owner->id, $rows[0]['authorId']);

        $em->remove($stored);
        $em->flush();
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_replies WHERE item_id = ?', [(string) $item->id]));
    }

    public function test_reusing_a_submission_id_with_changed_text_preserves_the_first_reply(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'reply-conflict');
        $item = $this->item($em, $this->project($em, $owner, 'reply-conflict'));
        $em->flush();
        $handler = self::getContainer()->get(ReplyToInboxItemHandler::class);
        $submissionId = (string) Uuid::v4();
        $handler(new ReplyToInboxItemCommand($item, $owner, 'Original.', $submissionId));
        try {
            $handler(new ReplyToInboxItemCommand($item, $owner, 'Changed draft.', $submissionId));
            self::fail('A replay must not replace a reply.');
        } catch (DomainErrors $error) {
            self::assertSame(['submissionId' => 'inbox.reply.error.submission'], $error->errors);
        }
        self::assertTrue($em->isOpen());
        self::assertSame(['Original.'], $em->getConnection()->fetchFirstColumn('SELECT body FROM inbox_replies WHERE item_id = ?', [(string) $item->id]));
    }

    #[TestWith(['', 'body'])]
    #[TestWith(['   ', 'body'])]
    public function test_empty_feedback_is_refused(string $body, string $field): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'reply-empty');
        $item = $this->item($em, $this->project($em, $owner, 'reply-empty'));
        $em->flush();
        $handler = self::getContainer()->get(ReplyToInboxItemHandler::class);
        try {
            $handler(new ReplyToInboxItemCommand($item, $owner, $body, (string) Uuid::v4()));
            self::fail('An empty reply must not be saved.');
        } catch (DomainErrors $error) {
            self::assertArrayHasKey($field, $error->errors);
        }
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_replies WHERE item_id = ?', [(string) $item->id]));
    }
}
