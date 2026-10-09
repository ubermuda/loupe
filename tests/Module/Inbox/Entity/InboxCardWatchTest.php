<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Entity;

use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWaitReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWaitType;
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
        $watch->waits->add(new InboxCardWait($watch, InboxCardWaitTrigger::RunBlocked, InboxCardWaitType::WorkerRun, InboxCardWaitReason::Blocked, runId: Uuid::v7(), startedAt: new \DateTimeImmutable('2026-09-28 10:05:00')));
        $watch->waits->add(new InboxCardWait($watch, InboxCardWaitTrigger::DocumentInReview, InboxCardWaitType::Document, InboxCardWaitReason::WaitingForReview, documentId: $documentId, versionNumber: 2, startedAt: new \DateTimeImmutable('2026-09-28 10:00:00')));
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
        $wait = new InboxCardWait($watch, InboxCardWaitTrigger::DocumentInReview, InboxCardWaitType::Document, InboxCardWaitReason::WaitingForReview, documentId: Uuid::v7(), versionNumber: 1);
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
        $watch->waits->add(new InboxCardWait($watch, InboxCardWaitTrigger::RunGaveUp, InboxCardWaitType::WorkerRun, InboxCardWaitReason::GaveUp, runId: Uuid::v7()));
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

        $document = new InboxCardWait($watch, InboxCardWaitTrigger::DocumentInReview, InboxCardWaitType::Document, InboxCardWaitReason::WaitingForReview, documentId: $documentId, versionNumber: 3);
        $run = new InboxCardWait($watch, InboxCardWaitTrigger::RunWaitingForPerson, InboxCardWaitType::WorkerRun, InboxCardWaitReason::WaitsForPerson, runId: $runId);

        self::assertSame('document-in-review:01a0e5cf-c001-7967-9205-b049b12f0957:3', $document->key());
        self::assertSame('run-waiting-for-person:01a0e91e-8210-768c-ae61-cba18491a4fc', $run->key());
        self::assertSame($document->key(), InboxCardWait::computeKey(InboxCardWaitTrigger::DocumentInReview, documentId: $documentId, versionNumber: 3));
        self::assertSame($run->key(), InboxCardWait::computeKey(InboxCardWaitTrigger::RunWaitingForPerson, runId: $runId));
        self::assertNotSame($document->key(), InboxCardWait::computeKey(InboxCardWaitTrigger::DocumentInReview, documentId: $documentId, versionNumber: 4));
    }

    public function test_the_key_of_a_pull_request_wait_names_the_pull_request_and_its_head_commit(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-pull-request-key'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $pullRequestId = Uuid::fromString('01a0f3c2-5d10-7b5e-9c1a-2f4e8d6b0a11');
        $headSha = str_repeat('a1', 20);

        $wait = new InboxCardWait($watch, InboxCardWaitTrigger::PullRequestReady, InboxCardWaitType::PullRequest, InboxCardWaitReason::WaitingForReview, pullRequestId: $pullRequestId, headSha: $headSha);

        self::assertSame('pull-request-ready:01a0f3c2-5d10-7b5e-9c1a-2f4e8d6b0a11:'.$headSha, $wait->key());
        self::assertSame($wait->key(), InboxCardWait::computeKey(InboxCardWaitTrigger::PullRequestReady, pullRequestId: $pullRequestId, headSha: $headSha));
        self::assertNotSame($wait->key(), InboxCardWait::computeKey(InboxCardWaitTrigger::PullRequestReady, pullRequestId: $pullRequestId, headSha: str_repeat('b2', 20)));
        self::assertNotSame($wait->key(), InboxCardWait::computeKey(InboxCardWaitTrigger::PullRequestFixStopped, pullRequestId: $pullRequestId, headSha: $headSha));
    }

    public function test_a_pull_request_wait_round_trips(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-pull-request-round-trip'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $pullRequestId = Uuid::v7();
        $headSha = str_repeat('c3', 32);
        $wait = new InboxCardWait($watch, InboxCardWaitTrigger::PullRequestFixStopped, InboxCardWaitType::PullRequest, InboxCardWaitReason::FixStopped, pullRequestId: $pullRequestId, headSha: $headSha);
        $watch->waits->add($wait);
        $this->em->flush();
        $waitId = $wait->id;
        $this->em->clear();

        $stored = $this->em->find(InboxCardWait::class, $waitId);
        self::assertInstanceOf(InboxCardWait::class, $stored);
        self::assertSame(InboxCardWaitTrigger::PullRequestFixStopped, $stored->trigger);
        self::assertEquals($pullRequestId, $stored->pullRequestId);
        self::assertSame($headSha, $stored->headSha);
        self::assertNull($stored->documentId);
        self::assertNull($stored->runId);
    }

    /** @param array{?string, ?int, ?string, ?string, ?string} $ids an id string stands for an id the wait carries, and the last entry is the head sha */
    #[TestWith([InboxCardWaitTrigger::DocumentInReview, [null, 1, null, null, null]])]
    #[TestWith([InboxCardWaitTrigger::DocumentInReview, ['document', null, null, null, null]])]
    #[TestWith([InboxCardWaitTrigger::DocumentInReview, ['document', 1, 'run', null, null]])]
    #[TestWith([InboxCardWaitTrigger::DocumentInReview, ['document', 1, null, 'pull-request', null]])]
    #[TestWith([InboxCardWaitTrigger::DocumentInReview, ['document', 1, null, null, 'abc123']])]
    #[TestWith([InboxCardWaitTrigger::RunBlocked, [null, null, null, null, null]])]
    #[TestWith([InboxCardWaitTrigger::RunBlocked, ['document', 1, 'run', null, null]])]
    #[TestWith([InboxCardWaitTrigger::RunBlocked, [null, null, 'run', 'pull-request', null]])]
    #[TestWith([InboxCardWaitTrigger::RunBlocked, [null, null, 'run', null, 'abc123']])]
    #[TestWith([InboxCardWaitTrigger::PullRequestReady, [null, null, null, null, 'abc123']])]
    #[TestWith([InboxCardWaitTrigger::PullRequestReady, [null, null, null, 'pull-request', null]])]
    #[TestWith([InboxCardWaitTrigger::PullRequestReady, [null, null, null, 'pull-request', '']])]
    #[TestWith([InboxCardWaitTrigger::PullRequestReady, [null, null, 'run', 'pull-request', 'abc123']])]
    #[TestWith([InboxCardWaitTrigger::PullRequestFixStopped, ['document', null, null, 'pull-request', 'abc123']])]
    #[TestWith([InboxCardWaitTrigger::PullRequestFixStopped, [null, 1, null, 'pull-request', 'abc123']])]
    public function test_a_wait_carries_exactly_the_ids_of_its_trigger(InboxCardWaitTrigger $trigger, array $ids): void
    {
        [$documentId, $versionNumber, $runId, $pullRequestId, $headSha] = $ids;

        $this->expectException(\InvalidArgumentException::class);
        InboxCardWait::computeKey(
            $trigger,
            null === $documentId ? null : Uuid::v7(),
            $versionNumber,
            null === $runId ? null : Uuid::v7(),
            null === $pullRequestId ? null : Uuid::v7(),
            $headSha,
        );
    }

    public function test_a_pause_wait_names_its_pause_and_round_trips(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-pause'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $pauseId = Uuid::fromString('01a0f3c2-5d10-7b5e-9c1a-2f4e8d6b0a22');
        $wait = new InboxCardWait($watch, InboxCardWaitTrigger::CardPaused, InboxCardWaitType::CardPause, InboxCardWaitReason::PauseRule, pauseId: $pauseId);
        $watch->waits->add($wait);
        $this->em->flush();
        $waitId = $wait->id;
        $this->em->clear();

        $stored = $this->em->find(InboxCardWait::class, $waitId);
        self::assertInstanceOf(InboxCardWait::class, $stored);
        self::assertSame('card-paused:01a0f3c2-5d10-7b5e-9c1a-2f4e8d6b0a22', $stored->key());
        self::assertEquals($pauseId, $stored->pauseId);
        self::assertNotSame($stored->key(), InboxCardWait::computeKey(InboxCardWaitTrigger::CardPaused, pauseId: Uuid::v7()));
    }

    public function test_a_pause_wait_carries_a_pause_and_nothing_else(): void
    {
        foreach ([
            [null, null, null, null, null],
            [Uuid::v7(), null, null, null, null],
            [null, 1, null, null, null],
            [null, null, Uuid::v7(), null, null],
            [null, null, null, Uuid::v7(), 'abc123'],
        ] as $index => [$documentId, $versionNumber, $runId, $pullRequestId, $headSha]) {
            try {
                InboxCardWait::computeKey(InboxCardWaitTrigger::CardPaused, $documentId, $versionNumber, $runId, $pullRequestId, $headSha, 0 === $index ? null : Uuid::v7());
                self::fail(\sprintf('Case %d was accepted.', $index));
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function test_only_a_pause_wait_names_a_pause(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InboxCardWait::computeKey(InboxCardWaitTrigger::RunBlocked, runId: Uuid::v7(), pauseId: Uuid::v7());
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
