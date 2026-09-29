<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Entity;

use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxCardWatchTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
    }

    public function test_a_watch_and_its_waits_round_trip_in_start_order(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-round-trip'), 'inbox');
        $cardId = Uuid::v7();
        $documentId = Uuid::v7();
        $watch = $this->watch($project, $cardId, 7);
        $watch->waits->add(new InboxCardWait($watch, InboxCardWaitTrigger::RunBlocked, 'Run blocked: preview not seeded', runId: Uuid::v7(), startedAt: new \DateTimeImmutable('2026-09-28 10:05:00')));
        $watch->waits->add(new InboxCardWait($watch, InboxCardWaitTrigger::DocumentInReview, 'Tech design in review, version 2', documentId: $documentId, versionNumber: 2, startedAt: new \DateTimeImmutable('2026-09-28 10:00:00')));
        $this->em->flush();
        $watchId = $watch->id;
        $this->em->clear();

        $stored = $this->em->find(InboxCardWatch::class, $watchId);
        self::assertInstanceOf(InboxCardWatch::class, $stored);
        self::assertEquals($cardId, $stored->cardId);
        self::assertSame(7, $stored->cardNumber);
        self::assertSame(InboxItemKind::Wait, $stored->item->kind);
        self::assertSame([InboxCardWaitTrigger::DocumentInReview, InboxCardWaitTrigger::RunBlocked], array_map(static fn (InboxCardWait $wait): InboxCardWaitTrigger => $wait->trigger, array_values($stored->waits->toArray())));
        $document = $stored->waits->first();
        self::assertInstanceOf(InboxCardWait::class, $document);
        self::assertEquals($documentId, $document->documentId);
        self::assertSame(2, $document->versionNumber);
        self::assertNull($document->runId);
        self::assertNull($document->endedAt);
        self::assertNull($document->endReason);
    }

    public function test_an_ended_wait_keeps_its_end_reason(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-end'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $wait = new InboxCardWait($watch, InboxCardWaitTrigger::DocumentInReview, 'Design in review, version 1', documentId: Uuid::v7(), versionNumber: 1);
        $watch->waits->add($wait);
        $wait->endedAt = new \DateTimeImmutable();
        $wait->endReason = InboxCardWaitEndReason::CardFinished;
        $this->em->flush();
        $waitId = $wait->id;
        $this->em->clear();

        self::assertSame(InboxCardWaitEndReason::CardFinished, $this->em->find(InboxCardWait::class, $waitId)?->endReason);
    }

    public function test_a_card_holds_one_open_watch(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-open-twice'), 'inbox');
        $cardId = Uuid::v7();
        $this->watch($project, $cardId, number: 1);
        $this->watch($project, $cardId, number: 2);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function test_a_card_opens_a_new_watch_once_its_watch_is_closed(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-reopen'), 'inbox');
        $cardId = Uuid::v7();
        $closed = $this->watch($project, $cardId, number: 1);
        $closed->closedAt = new \DateTimeImmutable();
        $closed->dismissedAt = new \DateTimeImmutable();
        $this->watch($project, $cardId, number: 2);
        $this->em->flush();

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_card_watches WHERE card_id = :id', ['id' => (string) $cardId]));
    }

    public function test_deleting_the_item_removes_its_watch_and_waits(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-cascade'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $watch->waits->add(new InboxCardWait($watch, InboxCardWaitTrigger::RunGaveUp, 'Run gave up', runId: Uuid::v7()));
        $this->em->flush();
        $watchId = (string) $watch->id;

        $this->connection->executeStatement('DELETE FROM inbox_items WHERE id = :id', ['id' => (string) $watch->item->id]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_card_watches WHERE id = :id', ['id' => $watchId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_card_waits WHERE watch_id = :id', ['id' => $watchId]));
    }

    public function test_a_watch_belongs_to_a_wait_item(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-question'), 'inbox');

        $this->expectException(\InvalidArgumentException::class);
        new InboxCardWatch($this->item($this->em, $project), Uuid::v7(), 1);
    }

    public function test_the_key_names_the_document_version_or_the_run(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-key'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $documentId = Uuid::fromString('01a0e5cf-c001-7967-9205-b049b12f0957');
        $runId = Uuid::fromString('01a0e91e-8210-768c-ae61-cba18491a4fc');

        $document = new InboxCardWait($watch, InboxCardWaitTrigger::DocumentInReview, 'In review', documentId: $documentId, versionNumber: 3);
        $run = new InboxCardWait($watch, InboxCardWaitTrigger::RunWaitingForPerson, 'Waiting', runId: $runId);

        self::assertSame('document-in-review:01a0e5cf-c001-7967-9205-b049b12f0957:3', $document->key());
        self::assertSame('run-waiting-for-person:01a0e91e-8210-768c-ae61-cba18491a4fc', $run->key());
        self::assertSame($document->key(), InboxCardWait::computeKey(InboxCardWaitTrigger::DocumentInReview, documentId: $documentId, versionNumber: 3));
        self::assertSame($run->key(), InboxCardWait::computeKey(InboxCardWaitTrigger::RunWaitingForPerson, runId: $runId));
        self::assertNotSame($document->key(), InboxCardWait::computeKey(InboxCardWaitTrigger::DocumentInReview, documentId: $documentId, versionNumber: 4));
    }

    /** @param array{?string, ?int, ?string} $ids a string stands for an id the wait carries */
    #[TestWith([InboxCardWaitTrigger::DocumentInReview, [null, 1, null]])]
    #[TestWith([InboxCardWaitTrigger::DocumentInReview, ['document', null, null]])]
    #[TestWith([InboxCardWaitTrigger::DocumentInReview, ['document', 1, 'run']])]
    #[TestWith([InboxCardWaitTrigger::RunBlocked, [null, null, null]])]
    #[TestWith([InboxCardWaitTrigger::RunBlocked, ['document', 1, 'run']])]
    public function test_a_wait_carries_exactly_the_ids_of_its_trigger(InboxCardWaitTrigger $trigger, array $ids): void
    {
        [$documentId, $versionNumber, $runId] = $ids;

        $this->expectException(\InvalidArgumentException::class);
        InboxCardWait::computeKey($trigger, null === $documentId ? null : Uuid::v7(), $versionNumber, null === $runId ? null : Uuid::v7());
    }

    public function test_a_wait_reason_fits_its_column(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-reason'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());

        $this->expectException(\InvalidArgumentException::class);
        new InboxCardWait($watch, InboxCardWaitTrigger::RunBlocked, str_repeat('a', InboxCardWait::MAX_REASON_LENGTH + 1), runId: Uuid::v7());
    }

    private function watch(Project $project, Uuid $cardId, int $cardNumber = 1, int $number = 1): InboxCardWatch
    {
        $item = new InboxItem(project: $project, number: $number, kind: InboxItemKind::Wait, title: '#'.$cardNumber.' Ship it', blocking: true);
        $this->em->persist($item);
        $watch = new InboxCardWatch($item, $cardId, $cardNumber);
        $this->em->persist($watch);

        return $watch;
    }
}
