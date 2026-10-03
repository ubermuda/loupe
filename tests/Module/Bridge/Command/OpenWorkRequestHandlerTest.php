<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\OpenWorkRequestCommand;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\DispatchedEvents;
use App\Tests\Support\RecordingAuditor;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class OpenWorkRequestHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-01T12:00:00+00:00';

    public function test_a_request_is_stored_and_written_to_the_outbox(): void
    {
        $this->boot();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $project = $this->scenario('open-stored');
        $cardId = Uuid::v7();
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);
        $depth = $this->em()->getConnection()->getTransactionNestingLevel();

        $request = $this->open($project, $cardId, capability: 'interactive');

        $this->em()->clear();
        $stored = $this->em()->find(WorkRequest::class, $request->id);
        self::assertInstanceOf(WorkRequest::class, $stored);
        self::assertSame((string) $project->id, (string) $stored->project->id);
        self::assertSame((string) $cardId, (string) $stored->cardId);
        self::assertSame(7, $stored->cardNumber);
        self::assertSame('implement', $stored->kind);
        self::assertSame('interactive', $stored->capability);
        self::assertSame('implement-on-entry', $stored->ruleId);
        self::assertSame(WorkRequestState::Open, $stored->state);
        self::assertSame(self::NOW, $stored->createdAt->format(\DateTimeInterface::ATOM));

        self::assertSame([[
            'type' => 'bridge.work_request',
            'projectId' => (string) $project->id,
            'subject' => ['type' => 'work-request', 'id' => (string) $request->id],
            'workRequestId' => (string) $request->id,
            'kind' => 'implement',
            'capability' => 'interactive',
            'state' => 'open',
            'cardId' => (string) $cardId,
            'cardNumber' => 7,
            'ruleId' => 'implement-on-entry',
            'createdAt' => self::NOW,
        ]], $this->outboxPayloads());

        $record = $audit->record('bridge.work_request_opened');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame((string) $request->id, $record->subject?->id);
        self::assertSame('implement', $record->context['kind']);
        self::assertSame((string) $cardId, $record->context['cardId']);

        self::assertEquals([new WorkRequestChanged($project->id ?? throw new \LogicException(), $cardId, $request->id ?? throw new \LogicException(), WorkRequestState::Open)], $changes->events());
        self::assertSame([$depth], $changes->transactionDepths());
    }

    public function test_a_second_live_request_for_the_card_and_kind_is_refused(): void
    {
        $this->boot();
        $project = $this->scenario('open-twice');
        $cardId = Uuid::v7();
        $this->open($project, $cardId);
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);

        try {
            $this->open($project, $cardId);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['card' => OpenWorkRequestHandler::LIVE], $e->errors);
        }
        self::assertSame([], $changes->events());

        self::assertTrue($this->em()->isOpen());
        self::assertSame(1, $this->countRequests());
        self::assertCount(1, $this->outboxPayloads());
    }

    /**
     * The test transaction holds the lock of the open, so a second session
     * cannot take it. An advisory lock is reentrant in one session.
     */
    public function test_an_open_locks_the_card_and_kind_until_the_transaction_ends(): void
    {
        $this->boot();
        $project = $this->scenario('open-lock');
        $cardId = Uuid::v7();
        $this->open($project, $cardId);
        $other = DriverManager::getConnection($this->em()->getConnection()->getParams());

        try {
            self::assertNotSame($this->em()->getConnection()->fetchOne('SELECT pg_backend_pid()'), $other->fetchOne('SELECT pg_backend_pid()'));
            $tryLock = static fn (Uuid $card, string $kind): bool => (bool) $other->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtext(?))',
                ['work_request:'.$card->toRfc4122().':'.$kind],
            );
            self::assertFalse($tryLock($cardId, 'implement'));
            self::assertTrue($tryLock($cardId, 'design'));
            self::assertTrue($tryLock(Uuid::v7(), 'implement'));
        } finally {
            $other->close();
        }
    }

    /** The unique index backs the lock up. A refusal there closes the entity manager. */
    public function test_a_second_live_request_that_passes_the_read_is_refused_by_the_index(): void
    {
        $this->boot();
        $project = $this->scenario('open-race');
        $cardId = Uuid::v7();
        $this->open($project, $cardId);
        $blind = $this->createStub(WorkRequestRepository::class);
        $blind->method('hasLive')->willReturn(false);
        $handler = new OpenWorkRequestHandler($blind, $this->service(OutboxWriter::class), $this->em(), new MockClock(self::NOW), $this->service(Auditor::class), $this->service(WorkRequestAnnouncer::class));

        try {
            $handler(new OpenWorkRequestCommand($project, $cardId, 7, 'implement', null, 'implement-on-entry'));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['card' => OpenWorkRequestHandler::LIVE], $e->errors);
        }

        self::assertSame(1, $this->countRequests());
        self::assertCount(1, $this->outboxPayloads());
    }

    public function test_another_kind_or_a_settled_request_does_not_block_a_new_one(): void
    {
        $this->boot();
        $project = $this->scenario('open-again');
        $cardId = Uuid::v7();
        $this->seedWorkRequest($this->em(), $project, cardId: $cardId, state: WorkRequestState::Done);

        $this->open($project, $cardId);
        $this->open($project, $cardId, kind: 'design');

        self::assertSame(3, $this->countRequests());
    }

    /** @return iterable<string, array{string, ?string, string, array<string, string>}> */
    public static function invalid(): iterable
    {
        yield 'kind with a space' => ['im plement', null, 'implement-on-entry', ['kind' => OpenWorkRequestHandler::INVALID_KIND]];
        yield 'kind in capitals' => ['Implement', null, 'implement-on-entry', ['kind' => OpenWorkRequestHandler::INVALID_KIND]];
        yield 'kind over the cap' => [str_repeat('a', 41), null, 'implement-on-entry', ['kind' => OpenWorkRequestHandler::INVALID_KIND]];
        yield 'empty capability' => ['implement', '', 'implement-on-entry', ['capability' => OpenWorkRequestHandler::INVALID_CAPABILITY]];
        yield 'rule with free text' => ['implement', null, 'Implement on entry', ['ruleId' => OpenWorkRequestHandler::INVALID_RULE]];
        yield 'all three' => ['', 'A', '', [
            'kind' => OpenWorkRequestHandler::INVALID_KIND,
            'capability' => OpenWorkRequestHandler::INVALID_CAPABILITY,
            'ruleId' => OpenWorkRequestHandler::INVALID_RULE,
        ]];
    }

    /** @param array<string, string> $errors */
    #[DataProvider('invalid')]
    public function test_a_malformed_request_is_refused(string $kind, ?string $capability, string $ruleId, array $errors): void
    {
        $this->boot();
        $project = $this->scenario('open-invalid');

        try {
            $this->open($project, Uuid::v7(), $kind, $capability, $ruleId);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }

        self::assertSame(0, $this->countRequests());
        self::assertSame([], $this->outboxPayloads());
    }

    /** Nothing in production calls the handler yet, so the compiled container holds none. */
    private function handler(): OpenWorkRequestHandler
    {
        return new OpenWorkRequestHandler(
            $this->service(WorkRequestRepository::class),
            $this->service(OutboxWriter::class),
            $this->em(),
            new MockClock(self::NOW),
            $this->service(Auditor::class),
            $this->service(WorkRequestAnnouncer::class),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function boot(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    private function scenario(string $name): Project
    {
        $em = $this->em();

        return $this->project($em, $this->user($em, $name.'@example.com'), 'Project '.substr(md5($name), 0, 8));
    }

    private function open(Project $project, Uuid $cardId, string $kind = 'implement', ?string $capability = null, string $ruleId = 'implement-on-entry'): WorkRequest
    {
        $handler = $this->handler();

        return $handler(new OpenWorkRequestCommand($project, $cardId, 7, $kind, $capability, $ruleId));
    }

    private function countRequests(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests');
    }

    /** @return list<array<string, mixed>> */
    private function outboxPayloads(): array
    {
        /** @var list<string> $payloads */
        $payloads = $this->em()->getConnection()->fetchFirstColumn(
            "SELECT payload FROM outbox_events WHERE type = 'bridge.work_request' ORDER BY sequence",
        );

        return array_map(static fn (string $payload): array => json_decode($payload, true, flags: \JSON_THROW_ON_ERROR), $payloads);
    }
}
