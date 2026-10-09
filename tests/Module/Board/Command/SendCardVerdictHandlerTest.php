<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\SendCardVerdictCommand;
use App\Module\Board\Command\SendCardVerdictHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictDeliveryState;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Board\Repository\CardVerdictRepository;
use App\Module\Board\Service\CardNoteSnapshot;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Tests\Module\Board\CardVerdictScenario;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;

final class SendCardVerdictHandlerTest extends KernelTestCase
{
    use BoardToolScenario;
    use CardVerdictScenario;

    private EntityManagerInterface $em;
    private RecordingAuditor $audit;
    private Project $project;
    private User $reviewer;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $this->project = $this->makeProject('verdict-send');
        $this->reviewer = $this->project->owner;
    }

    public function test_it_stores_the_verdict_with_a_copy_of_the_pending_notes_and_one_pending_delivery_per_pull_request(): void
    {
        $card = $this->card($this->project);
        $first = $this->linkedPullRequest($card, 7);
        $second = $this->linkedPullRequest($card, 8);
        $this->linkedPullRequest($card, 9);
        $kept = $this->note($card, 'The footer overlaps the launcher.');
        $this->note($card, 'Already fixed.', SiteReviewCommentStatus::Addressed, 1);
        $this->em->flush();

        $verdict = $this->send($card, CardVerdictKind::RequestChanges, [(string) $first->id, (string) $second->id], '  Please fix the footer.  ');

        $this->em->clear();
        $stored = $this->em->find(CardVerdict::class, $verdict->id);
        self::assertInstanceOf(CardVerdict::class, $stored);
        self::assertSame(CardVerdictKind::RequestChanges, $stored->kind);
        self::assertSame('Please fix the footer.', $stored->message);
        self::assertSame($this->reviewer->id?->toRfc4122(), $stored->reviewer?->id?->toRfc4122());
        self::assertSame([[
            'id' => (string) $kept->id,
            'url' => 'https://app.example/page',
            'body' => 'The footer overlaps the launcher.',
            'anchorCount' => 1,
        ]], $stored->notes);

        $deliveries = $this->service(CardVerdictDeliveryRepository::class)->findBy(['verdict' => $stored]);
        self::assertEqualsCanonicalizing(
            [(string) $first->id, (string) $second->id],
            array_map(static fn (CardVerdictDelivery $delivery): string => (string) $delivery->pullRequest->id, $deliveries),
        );
        foreach ($deliveries as $delivery) {
            self::assertSame(CardVerdictDeliveryState::Pending, $delivery->state);
            self::assertNull($delivery->settledAt);
        }
    }

    public function test_a_later_edit_of_a_note_leaves_the_copy_alone(): void
    {
        $card = $this->card($this->project);
        $note = $this->note($card, 'Original text.');
        $this->em->flush();
        $verdict = $this->send($card, CardVerdictKind::Comment, [], 'Look at this.');

        $note->body = 'Edited text.';
        $this->em->flush();
        $this->em->clear();

        $stored = $this->em->find(CardVerdict::class, $verdict->id);
        self::assertInstanceOf(CardVerdict::class, $stored);
        self::assertSame('Original text.', $stored->notes[0]['body']);
    }

    public function test_it_writes_a_verdict_history_entry_audits_it_and_asks_the_engine_to_evaluate_the_card(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $this->note($card, 'One note.');
        $this->em->flush();

        $this->send($card, CardVerdictKind::Approve, [(string) $pullRequest->id], '');

        $event = $this->service(CardEventRepository::class)->findOneBy(['card' => $card, 'kind' => CardEventKind::Verdict]);
        self::assertInstanceOf(CardEvent::class, $event);
        self::assertSame(Actor::Human, $event->actorKind);
        self::assertSame($this->reviewer->id?->toRfc4122(), $event->actorUser?->id?->toRfc4122());
        self::assertSame(['kind' => 'approve', 'noteCount' => 1, 'pullRequestCount' => 1], $event->detail);

        $context = $this->audit->record('board.verdict_sent')->context;
        self::assertSame('approve', $context['kind']);
        self::assertSame(1, $context['noteCount']);
        self::assertSame(1, $context['pullRequestCount']);
        self::assertArrayNotHasKey('message', $context);

        self::assertEquals([new EvaluateCard((string) $card->id)], $this->evaluations());
    }

    public function test_a_verdict_asks_the_engine_to_evaluate_every_card_on_the_shared_pull_request(): void
    {
        $card = $this->card($this->project);
        $sharing = $this->card($this->project, 'next', 2);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $this->em->persist(new CardPullRequest($sharing, 'https://github.com/Acme/Widgets/pull/7', Forge::GitHub, 'Acme/Widgets', 7));
        $this->note($card, 'One note.');
        $this->em->flush();

        $this->send($card, CardVerdictKind::Comment, [(string) $pullRequest->id], 'Look.');

        self::assertEquals([new EvaluateCard((string) $card->id), new EvaluateCard((string) $sharing->id)], $this->evaluations());
    }

    public function test_an_approval_needs_no_message(): void
    {
        $card = $this->card($this->project);
        $this->em->flush();

        $verdict = $this->send($card, CardVerdictKind::Approve, [], '');

        self::assertSame('', $verdict->message);
        self::assertSame(CardVerdictKind::Approve, $verdict->kind);
    }

    /** @return iterable<string, array{CardVerdictKind, string}> */
    public static function emptyMessages(): iterable
    {
        yield 'request changes with no text' => [CardVerdictKind::RequestChanges, ''];
        yield 'request changes with spaces' => [CardVerdictKind::RequestChanges, " \n\t "];
        yield 'comment with no text' => [CardVerdictKind::Comment, ''];
        yield 'comment with spaces' => [CardVerdictKind::Comment, '   '];
    }

    #[DataProvider('emptyMessages')]
    public function test_it_refuses_an_empty_message_when_the_verdict_needs_one(CardVerdictKind $kind, string $message): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $this->em->flush();

        $this->assertRefused('message', SendCardVerdictHandler::MESSAGE_REQUIRED, $card, $kind, [(string) $pullRequest->id], $message);
        $this->assertNothingStored();
    }

    public function test_it_refuses_a_pull_request_that_is_not_an_open_pull_request_of_the_card(): void
    {
        $card = $this->card($this->project);
        $other = $this->card($this->project, number: 2);
        $own = $this->linkedPullRequest($card, 7);
        $foreign = $this->linkedPullRequest($other, 8);
        $merged = $this->linkedPullRequest($card, 9, PullRequestState::Merged);
        $elsewhere = $this->linkedPullRequest($card, 10, forge: 'gitlab');
        $this->em->flush();

        foreach ([(string) $foreign->id, (string) $merged->id, (string) $elsewhere->id, '0198a2c0-0000-7000-8000-000000000000', 'not-a-uuid'] as $id) {
            $this->assertRefused('pullRequestIds', SendCardVerdictHandler::PULL_REQUEST_NOT_ON_CARD, $card, CardVerdictKind::Approve, [(string) $own->id, $id], '');
        }
        $this->assertNothingStored();
    }

    public function test_a_pull_request_picked_twice_gets_one_delivery(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $this->em->flush();

        $verdict = $this->send($card, CardVerdictKind::Approve, [(string) $pullRequest->id, strtoupper((string) $pullRequest->id)], '');

        self::assertCount(1, $this->service(CardVerdictDeliveryRepository::class)->findBy(['verdict' => $verdict]));
    }

    public function test_it_refuses_a_card_of_another_project(): void
    {
        $foreignProject = $this->makeProject('verdict-foreign');
        $foreign = $this->card($foreignProject);
        $this->em->flush();

        $this->assertRefused('card', SendCardVerdictHandler::CARD_GONE, $foreign, CardVerdictKind::Approve, [], '');
        $this->assertNothingStored();
    }

    public function test_it_refuses_a_card_id_that_names_no_card(): void
    {
        try {
            $this->handler()(new SendCardVerdictCommand($this->project, 'not-a-uuid', $this->reviewer, CardVerdictKind::Approve, [], '', Uuid::v7()));
            self::fail('A missing card must be refused.');
        } catch (DomainErrors $error) {
            self::assertSame(['card' => SendCardVerdictHandler::CARD_GONE], $error->errors);
        }
    }

    public function test_it_refuses_a_card_in_a_terminal_column(): void
    {
        $card = $this->card($this->project, 'done');
        $this->em->flush();

        $this->assertRefused('card', SendCardVerdictHandler::CARD_CLOSED, $card, CardVerdictKind::Approve, [], '');
        $this->assertNothingStored();
    }

    public function test_it_does_not_ask_the_engine_when_the_engine_is_off(): void
    {
        $card = $this->card($this->project);
        $this->em->flush();
        $evaluations = $this->createMock(CardEvaluations::class);
        $evaluations->method('isOn')->willReturn(false);
        $evaluations->expects(self::never())->method('forCards');

        $handler = new SendCardVerdictHandler(
            $this->service(CardRepository::class),
            $this->service(CardPullRequestRepository::class),
            $this->service(CardVerdictRepository::class),
            $this->service(CardVerdictDeliveryRepository::class),
            $this->service(CardNoteSnapshot::class),
            $this->service(CardEventRepository::class),
            $this->em,
            $evaluations,
            $this->service(Auditor::class),
        );
        $verdict = $handler(new SendCardVerdictCommand($this->project, (string) $card->id, $this->reviewer, CardVerdictKind::Approve, [], '', Uuid::v7()));

        self::assertNotNull($verdict->id);
    }

    public function test_the_same_submission_sent_again_returns_the_saved_verdict_and_stores_nothing_more(): void
    {
        $card = $this->card($this->project);
        $first = $this->linkedPullRequest($card, 7);
        $second = $this->linkedPullRequest($card, 8);
        $this->note($card, 'One note.');
        $this->em->flush();
        $submission = Uuid::v7();

        $saved = $this->send($card, CardVerdictKind::RequestChanges, [(string) $first->id, (string) $second->id], 'Fix it.', $submission);
        $retried = $this->send($card, CardVerdictKind::RequestChanges, [(string) $second->id, (string) $first->id], '  Fix it.  ', $submission);

        self::assertSame((string) $saved->id, (string) $retried->id);
        $connection = $this->em->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_card_verdicts'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_card_verdict_deliveries'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM board_card_events WHERE kind = 'verdict'"));
        self::assertCount(1, $this->audit->records('board.verdict_sent'));
        self::assertCount(2, $this->evaluations());
    }

    public function test_a_retry_still_finds_its_verdict_after_the_card_closed(): void
    {
        $card = $this->card($this->project);
        $this->em->flush();
        $submission = Uuid::v7();
        $saved = $this->send($card, CardVerdictKind::Approve, [], '', $submission);

        $card->column = $this->column($this->project, 'done');
        $this->em->flush();

        self::assertSame((string) $saved->id, (string) $this->send($card, CardVerdictKind::Approve, [], '', $submission)->id);
    }

    /** @return iterable<string, array{CardVerdictKind, string, bool}> */
    public static function changedContent(): iterable
    {
        yield 'another kind' => [CardVerdictKind::Comment, 'Fix it.', true];
        yield 'another message' => [CardVerdictKind::RequestChanges, 'Fix something else.', true];
        yield 'fewer pull requests' => [CardVerdictKind::RequestChanges, 'Fix it.', false];
    }

    #[DataProvider('changedContent')]
    public function test_a_submission_id_reused_with_other_content_is_refused(CardVerdictKind $kind, string $message, bool $bothPullRequests): void
    {
        $card = $this->card($this->project);
        $first = $this->linkedPullRequest($card, 7);
        $second = $this->linkedPullRequest($card, 8);
        $this->em->flush();
        $submission = Uuid::v7();
        $this->send($card, CardVerdictKind::RequestChanges, [(string) $first->id, (string) $second->id], 'Fix it.', $submission);

        $ids = $bothPullRequests ? [(string) $first->id, (string) $second->id] : [(string) $first->id];
        try {
            $this->send($card, $kind, $ids, $message, $submission);
            self::fail('The reused submission id must be refused.');
        } catch (DomainErrors $error) {
            self::assertSame(['submissionId' => SendCardVerdictHandler::SUBMISSION_REUSED], $error->errors);
        }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_card_verdicts'));
    }

    public function test_the_same_submission_id_on_another_card_stores_its_own_verdict(): void
    {
        $first = $this->card($this->project);
        $second = $this->card($this->project, number: 2);
        $this->em->flush();
        $submission = Uuid::v7();

        $one = $this->send($first, CardVerdictKind::Approve, [], '', $submission);
        $two = $this->send($second, CardVerdictKind::Approve, [], '', $submission);

        self::assertNotSame((string) $one->id, (string) $two->id);
    }

    /** @param list<string> $pullRequestIds */
    private function send(Card $card, CardVerdictKind $kind, array $pullRequestIds, string $message, ?Uuid $submissionId = null): CardVerdict
    {
        return $this->handler()(new SendCardVerdictCommand($this->project, (string) $card->id, $this->reviewer, $kind, $pullRequestIds, $message, $submissionId ?? Uuid::v7()));
    }

    /** @param list<string> $pullRequestIds */
    private function assertRefused(string $field, string $key, Card $card, CardVerdictKind $kind, array $pullRequestIds, string $message): void
    {
        try {
            $this->send($card, $kind, $pullRequestIds, $message);
            self::fail('The verdict must be refused.');
        } catch (DomainErrors $error) {
            self::assertSame([$field => $key], $error->errors);
        }
    }

    private function assertNothingStored(): void
    {
        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_card_verdicts'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_card_verdict_deliveries'));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM board_card_events WHERE kind = 'verdict'"));
        self::assertSame([], $this->evaluations());
        self::assertSame([], $this->audit->records('board.verdict_sent'));
    }

    /** @return list<EvaluateCard> */
    private function evaluations(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof EvaluateCard,
        ));
    }

    private function handler(): SendCardVerdictHandler
    {
        return $this->service(SendCardVerdictHandler::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
