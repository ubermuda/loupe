<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\OpenWorkRequestCommand;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Project\Entity\Project;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Module\Bridge\WorkSubject\RecordingWorkSubjectHandler;
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
        self::assertSame((string) $cardId, (string) $stored->subjectId);
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
            'subjectType' => 'card',
            'subjectId' => (string) $cardId,
            'kind' => 'implement',
            'capability' => 'interactive',
            'state' => 'open',
            'cardNumber' => 7,
            'ruleId' => 'implement-on-entry',
            'createdAt' => self::NOW,
            'resumeSessionId' => null,
            'context' => ['pullRequestNumber' => null, 'pullRequestUrl' => null, 'headSha' => null, 'reason' => null, 'documentId' => null],
        ]], $this->outboxPayloads());

        $record = $audit->record('bridge.work_request_opened');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame((string) $request->id, $record->subject?->id);
        self::assertSame('implement', $record->context['kind']);
        self::assertSame('card', $record->context['subjectType']);
        self::assertSame((string) $cardId, $record->context['subjectId']);

        self::assertEquals([new WorkRequestChanged($project->id ?? throw new \LogicException(), WorkSubject::CARD, $cardId, $request->id ?? throw new \LogicException(), WorkRequestState::Open)], $changes->events());
        self::assertSame([$depth], $changes->transactionDepths());
    }

    public function test_the_context_is_stored_and_written_to_the_outbox(): void
    {
        $this->boot();
        $project = $this->scenario('open-context');
        $context = new WorkRequestContext(42, 'https://github.com/acme/widgets/pull/42', 'abc1234', 'checks-failed', Uuid::v7()->toRfc4122());

        $request = $this->open($project, Uuid::v7(), kind: 'fix', context: $context);

        $this->em()->clear();
        $stored = $this->em()->find(WorkRequest::class, $request->id);
        self::assertInstanceOf(WorkRequest::class, $stored);
        self::assertEquals($context, $stored->context);
        self::assertSame($context->toArray(), $this->outboxPayloads()[0]['context']);
    }

    public function test_a_live_request_keeps_the_context_it_opened_with(): void
    {
        $this->boot();
        $project = $this->scenario('open-context-live');
        $cardId = Uuid::v7();
        $first = new WorkRequestContext(42, null, 'abc1234', 'conflict');
        $request = $this->open($project, $cardId, kind: 'fix', context: $first);

        try {
            $this->open($project, $cardId, kind: 'fix', context: new WorkRequestContext(43, null, 'def5678', 'checks-failed'));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['card' => OpenWorkRequestHandler::LIVE], $e->errors);
        }

        $this->em()->clear();
        $stored = $this->em()->find(WorkRequest::class, $request->id);
        self::assertInstanceOf(WorkRequest::class, $stored);
        self::assertEquals($first, $stored->context);
    }

    public function test_the_retry_of_a_request_whose_run_ended_unfinished_resumes_its_session(): void
    {
        $this->boot();
        $project = $this->scenario('open-resume');
        $cardId = Uuid::v7();
        $this->seedRun($this->em(), $project, receivedAt: new \DateTimeImmutable('2026-10-01 09:00:00'), workKind: 'implement', cardId: $cardId, state: WorkerRunState::Failed);
        $unfinished = $this->unfinishedRunOf($this->seedWorkRequest($this->em(), $project, cardId: $cardId, state: WorkRequestState::Done, createdAt: new \DateTimeImmutable('2026-10-01 10:00:00')));

        $request = $this->open($project, $cardId);

        self::assertSame((string) $unfinished->sessionId, (string) $request->resumeSessionId);
        self::assertSame((string) $unfinished->sessionId, $this->outboxPayloads()[0]['resumeSessionId']);
    }

    public function test_a_run_that_ended_unfinished_more_than_a_day_ago_is_not_resumed(): void
    {
        $this->boot();
        $project = $this->scenario('open-stale');
        $cardId = Uuid::v7();
        $previous = $this->seedWorkRequest($this->em(), $project, cardId: $cardId, state: WorkRequestState::Done, createdAt: new \DateTimeImmutable('2026-09-01 10:00:00'));
        $this->unfinishedRunOf($previous, endedAt: new \DateTimeImmutable('2026-09-30 11:59:00'));

        self::assertNull($this->open($project, $cardId)->resumeSessionId);
    }

    public function test_a_run_that_ended_unfinished_within_a_day_is_resumed(): void
    {
        $this->boot();
        $project = $this->scenario('open-fresh-enough');
        $cardId = Uuid::v7();
        $previous = $this->seedWorkRequest($this->em(), $project, cardId: $cardId, state: WorkRequestState::Done, createdAt: new \DateTimeImmutable('2026-09-30 10:00:00'));
        $unfinished = $this->unfinishedRunOf($previous, endedAt: new \DateTimeImmutable('2026-09-30 12:01:00'));

        self::assertSame((string) $unfinished->sessionId, (string) $this->open($project, $cardId)->resumeSessionId);
    }

    public function test_another_run_of_the_card_since_the_unfinished_run_starts_fresh(): void
    {
        $this->boot();
        $project = $this->scenario('open-other-run');
        $cardId = Uuid::v7();
        $this->unfinishedRunOf($this->seedWorkRequest($this->em(), $project, cardId: $cardId, state: WorkRequestState::Done, createdAt: new \DateTimeImmutable('2026-10-01 10:00:00')));
        $this->seedRun($this->em(), $project, receivedAt: new \DateTimeImmutable('2026-10-01 11:30:00'), workKind: 'plan', cardId: $cardId, state: WorkerRunState::Succeeded);

        self::assertNull($this->open($project, $cardId)->resumeSessionId);
    }

    public function test_a_request_of_another_rule_starts_fresh(): void
    {
        $this->boot();
        $project = $this->scenario('open-other-rule');
        $cardId = Uuid::v7();
        $this->unfinishedRunOf($this->seedWorkRequest($this->em(), $project, cardId: $cardId, state: WorkRequestState::Done, createdAt: new \DateTimeImmutable('2026-10-01 10:00:00'), ruleId: 'implement-on-retry'));

        self::assertNull($this->open($project, $cardId)->resumeSessionId);
    }

    public function test_a_later_request_of_the_rule_with_no_run_starts_fresh(): void
    {
        $this->boot();
        $project = $this->scenario('open-later-request');
        $cardId = Uuid::v7();
        $this->unfinishedRunOf($this->seedWorkRequest($this->em(), $project, cardId: $cardId, state: WorkRequestState::Done, createdAt: new \DateTimeImmutable('2026-10-01 10:00:00')));
        $this->seedWorkRequest($this->em(), $project, cardId: $cardId, state: WorkRequestState::Expired, createdAt: new \DateTimeImmutable('2026-10-01 11:00:00'));

        self::assertNull($this->open($project, $cardId)->resumeSessionId);
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function notUnfinished(): iterable
    {
        yield 'a success' => [WorkerRunState::Succeeded];
        yield 'a failure' => [WorkerRunState::Failed];
        yield 'a block' => [WorkerRunState::Blocked];
    }

    #[DataProvider('notUnfinished')]
    public function test_a_request_after_a_run_that_did_not_end_unfinished_starts_fresh(WorkerRunState $state): void
    {
        $this->boot();
        $project = $this->scenario('open-fresh-'.$state->value);
        $cardId = Uuid::v7();
        $previous = $this->seedWorkRequest($this->em(), $project, cardId: $cardId, state: WorkRequestState::Done, createdAt: new \DateTimeImmutable('2026-10-01 10:00:00'));
        $this->unfinishedRunOf($previous, state: $state);

        self::assertNull($this->open($project, $cardId)->resumeSessionId);
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
                ['work_request:card:'.$card->toRfc4122().':'.$kind],
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
        $handler = new OpenWorkRequestHandler($blind, $this->service(OutboxWriter::class), $this->em(), new MockClock(self::NOW), $this->service(Auditor::class), $this->service(WorkRequestAnnouncer::class), $this->service(WorkerRunRepository::class), new WorkSubjectHandlers([]));

        try {
            $handler(new OpenWorkRequestCommand($project, WorkSubject::card($cardId), 7, 'implement', null, 'implement-on-entry', new WorkRequestContext()));
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

    /** @return iterable<string, array{WorkSubject, ?int}> */
    public static function mismatchedCardNumbers(): iterable
    {
        yield 'a card with no number' => [WorkSubject::card(Uuid::v7()), null];
        yield 'another subject with a card number' => [new WorkSubject('analysis', Uuid::v7()), 7];
    }

    #[DataProvider('mismatchedCardNumbers')]
    public function test_a_card_number_goes_with_a_card_subject_alone(WorkSubject $subject, ?int $cardNumber): void
    {
        $this->boot();
        $project = $this->scenario('open-card-number');

        try {
            $this->handler()(new OpenWorkRequestCommand($project, $subject, $cardNumber, 'implement', null, 'implement-on-entry', new WorkRequestContext()));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['cardNumber' => OpenWorkRequestHandler::INVALID_CARD_NUMBER], $e->errors);
        }

        self::assertSame(0, $this->countRequests());
    }

    public function test_a_request_about_another_subject_opens_with_no_card(): void
    {
        $this->boot();
        $project = $this->scenario('open-other-subject');
        $subjectId = Uuid::v7();
        $card = $this->open($project, $subjectId);

        $request = $this->handler()(new OpenWorkRequestCommand($project, new WorkSubject('analysis', $subjectId), null, 'implement', null, 'insights.analysis', new WorkRequestContext()));

        self::assertNotSame($card, $request);
        self::assertSame('analysis', $request->subjectType);
        self::assertSame($subjectId, $request->subjectId);
        self::assertNull($request->cardNumber);
        $payloads = $this->outboxPayloads();
        self::assertCount(2, $payloads);
        self::assertSame('analysis', $payloads[1]['subjectType']);
        self::assertSame((string) $subjectId, $payloads[1]['subjectId']);
        self::assertNull($payloads[1]['cardNumber']);
        self::assertArrayNotHasKey('cardId', $payloads[1]);
    }

    public function test_a_subject_type_that_no_module_handles_is_refused(): void
    {
        $this->boot();
        $project = $this->scenario('open-unknown-subject');

        try {
            $this->handler()(new OpenWorkRequestCommand($project, new WorkSubject('report', Uuid::v7()), null, 'implement', null, 'insights.analysis', new WorkRequestContext()));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['subjectType' => OpenWorkRequestHandler::UNKNOWN_SUBJECT_TYPE], $e->errors);
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
            $this->service(WorkerRunRepository::class),
            new WorkSubjectHandlers([new RecordingWorkSubjectHandler()]),
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

    private function open(
        Project $project,
        Uuid $cardId,
        string $kind = 'implement',
        ?string $capability = null,
        string $ruleId = 'implement-on-entry',
        WorkRequestContext $context = new WorkRequestContext(),
    ): WorkRequest {
        $handler = $this->handler();

        return $handler(new OpenWorkRequestCommand($project, WorkSubject::card($cardId), 7, $kind, $capability, $ruleId, $context));
    }

    private function unfinishedRunOf(
        WorkRequest $request,
        \DateTimeImmutable $endedAt = new \DateTimeImmutable('2026-10-01 11:00:00'),
        WorkerRunState $state = WorkerRunState::Unfinished,
    ): WorkerRun {
        return $this->seedRun(
            $this->em(),
            $request->project,
            receivedAt: $request->createdAt->modify('+1 minute'),
            workKind: $request->kind,
            cardId: $request->subjectId,
            state: $state,
            workRequestId: $request->id,
            endedAt: $endedAt,
        );
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
