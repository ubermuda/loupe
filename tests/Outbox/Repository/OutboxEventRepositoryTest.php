<?php

declare(strict_types=1);

namespace App\Tests\Outbox\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Outbox\ActivityFamily;
use App\Outbox\Entity\OutboxEvent;
use App\Outbox\Repository\OutboxEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OutboxEventRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OutboxEventRepository $outboxEvents;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $outboxEvents = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outboxEvents);
        $this->outboxEvents = $outboxEvents;
    }

    public function test_claim_takes_only_events_still_owed_to_an_agent(): void
    {
        $project = $this->project('claim-a@example.com');
        $due = $this->event($project);
        $published = $this->event($project);
        $published->markPublished();
        $this->em->flush();

        $claimed = $this->outboxEvents->claimDueForPublish(10, new \DateTimeImmutable(), $this->inFiveMinutes());

        self::assertSame(
            [(string) $due->id],
            array_map(static fn (OutboxEvent $event): string => (string) $event->id, $claimed),
            'A published row is settled, so the claim must pass over it.',
        );
        self::assertNotNull($published->id);
    }

    public function test_a_claimed_event_is_not_handed_to_the_next_claim(): void
    {
        $project = $this->project('claim-b@example.com');
        $this->event($project);
        $this->em->flush();

        $now = new \DateTimeImmutable();
        $first = $this->outboxEvents->claimDueForPublish(10, $now, $this->inFiveMinutes($now));
        $second = $this->outboxEvents->claimDueForPublish(10, $now, $this->inFiveMinutes($now));

        // The lease, not a held lock, is what makes this true — and it is the
        // same mechanism that keeps two concurrent workers off the same row.
        self::assertCount(1, $first);
        self::assertSame([], $second);
    }

    public function test_a_claim_whose_lease_expired_becomes_due_again(): void
    {
        $project = $this->project('claim-c@example.com');
        $this->event($project);
        $this->em->flush();

        $claimedAt = new \DateTimeImmutable('-1 hour');
        self::assertCount(1, $this->outboxEvents->claimDueForPublish(10, $claimedAt, $this->inFiveMinutes($claimedAt)));

        // A worker that died mid-batch must not strand its rows for good.
        $now = new \DateTimeImmutable();
        self::assertCount(1, $this->outboxEvents->claimDueForPublish(10, $now, $this->inFiveMinutes($now)));
    }

    public function test_claim_honours_the_limit_and_takes_the_oldest_first(): void
    {
        $project = $this->project('claim-d@example.com');
        $first = $this->event($project);
        $this->event($project);
        $this->em->flush();

        $claimed = $this->outboxEvents->claimDueForPublish(1, new \DateTimeImmutable(), $this->inFiveMinutes());

        self::assertCount(1, $claimed);
        self::assertSame((string) $first->id, (string) $claimed[0]->id);
    }

    public function test_claim_honours_the_limit_when_the_planner_rescans_the_locking_subquery(): void
    {
        $project = $this->project('claim-rescan@example.com');
        $first = $this->event($project);
        $this->event($project);
        $this->event($project);
        $this->em->flush();

        // These settings force a nested loop that runs the locked, limited
        // subquery once per outer row. Each run skips the rows the update already
        // changed and locks the next one. Stale statistics can pick that plan too.
        $connection = $this->em->getConnection();
        foreach (['enable_material', 'enable_hashagg', 'enable_hashjoin', 'enable_mergejoin', 'enable_sort'] as $setting) {
            $connection->executeStatement(\sprintf('SET LOCAL %s = off', $setting));
        }

        $claimed = $this->outboxEvents->claimDueForPublish(1, new \DateTimeImmutable(), $this->inFiveMinutes());

        self::assertCount(1, $claimed);
        self::assertSame((string) $first->id, (string) $claimed[0]->id);
        self::assertSame(2, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM outbox_events WHERE project_id = ? AND next_attempt_at IS NULL',
            [(string) $project->id],
        ));
    }

    public function test_the_unsent_list_matches_what_the_drain_would_claim(): void
    {
        $project = $this->project('unsent-a@example.com');
        $other = $this->project('unsent-b@example.com');
        $owed = $this->event($project);
        $published = $this->event($project);
        $published->markPublished();
        $this->event($other);
        $this->em->flush();

        $unsent = $this->outboxEvents->findUnsentForProject($project);

        self::assertCount(1, $unsent);
        self::assertSame((string) $owed->id, (string) $unsent[0]->id);
        self::assertSame(1, $this->outboxEvents->countUnsent($project));
        self::assertSame(2, $this->outboxEvents->countUnsent());

        $projectNames = array_map(
            static fn (Project $candidate): string => $candidate->name,
            $this->outboxEvents->findProjectsWithUnsent(),
        );
        self::assertContains($project->name, $projectNames);
        self::assertContains($other->name, $projectNames);
    }

    public function test_a_type_scoped_count_ignores_another_producers_rows(): void
    {
        $project = $this->project('typed@example.com');
        $this->event($project);
        $this->event($project, type: 'board.card_moved');
        $this->em->flush();

        // The table is shared, so a caller that speaks for one producer must not
        // report a backlog that belongs to another.
        self::assertSame(2, $this->outboxEvents->countUnsent($project));
        self::assertSame(1, $this->outboxEvents->countUnsent($project, 'test.event'));
        self::assertSame(1, $this->outboxEvents->countUnsent($project, 'board.card_moved'));
    }

    public function test_the_activity_page_holds_one_project_newest_first(): void
    {
        $project = $this->project('activity-page@example.com');
        $other = $this->project('activity-page-other@example.com');
        $oldest = $this->activity($project, 'board.card_moved', createdAt: '-3 minutes');
        $newest = $this->activity($project, 'board.card_created', createdAt: '-1 minute');
        $middle = $this->activity($project, 'inbox.ask_opened', createdAt: '-2 minutes');
        $this->activity($other, 'board.card_moved');
        $this->em->flush();

        self::assertSame(
            [(string) $newest->id, (string) $middle->id, (string) $oldest->id],
            $this->activityIds($project),
        );
        self::assertSame(3, \count($this->outboxEvents->findPaginatedForProject($project, 1, 2, null, null)));
        self::assertSame([(string) $newest->id, (string) $middle->id], $this->activityIds($project, perPage: 2));
        self::assertSame([(string) $oldest->id], $this->activityIds($project, page: 2, perPage: 2));
    }

    public function test_events_written_in_the_same_instant_order_by_sequence(): void
    {
        $project = $this->project('activity-tie@example.com');
        $first = $this->activity($project, 'board.card_moved', createdAt: '2026-09-28 10:00:00');
        $this->em->flush();
        $second = $this->activity($project, 'board.card_moved', createdAt: '2026-09-28 10:00:00');
        $this->em->flush();

        self::assertSame([(string) $second->id, (string) $first->id], $this->activityIds($project));
    }

    public function test_the_family_filter_folds_legacy_review_events_into_document(): void
    {
        $project = $this->project('activity-family@example.com');
        $document = $this->activity($project, 'document.revised');
        $legacy = $this->activity($project, 'review.document_revised');
        $this->activity($project, 'board.card_moved');
        $this->activity($project, 'reviewer.assigned');
        $this->em->flush();

        self::assertEqualsCanonicalizing(
            [(string) $document->id, (string) $legacy->id],
            $this->activityIds($project, family: ActivityFamily::Document),
        );
    }

    public function test_the_family_prefix_underscore_matches_only_itself(): void
    {
        $project = $this->project('activity-family-underscore@example.com');
        $pullRequest = $this->activity($project, 'pull_request.merged');
        $this->activity($project, 'pullxrequest.merged');
        $this->em->flush();

        self::assertSame([(string) $pullRequest->id], $this->activityIds($project, family: ActivityFamily::PullRequest));
    }

    public function test_every_search_word_must_match_the_type_or_the_payload(): void
    {
        $project = $this->project('activity-search@example.com');
        $both = $this->activity($project, 'board.card_moved', '{"toStatus":"Review"}');
        $typeOnly = $this->activity($project, 'board.card_moved', '{"toStatus":"done"}');
        $payloadOnly = $this->activity($project, 'inbox.ask_opened', '{"title":"Moved"}');
        $this->activity($project, 'inbox.ask_closed', '{}');
        $this->em->flush();

        self::assertSame([(string) $both->id], $this->activityIds($project, search: 'CARD_moved review'));
        self::assertEqualsCanonicalizing(
            [(string) $both->id, (string) $typeOnly->id, (string) $payloadOnly->id],
            $this->activityIds($project, search: '  MOVED '),
        );
    }

    public function test_search_and_family_combine(): void
    {
        $project = $this->project('activity-search-family@example.com');
        $board = $this->activity($project, 'board.card_moved', '{"toStatus":"review"}');
        $this->activity($project, 'review.document_revised', '{}');
        $this->em->flush();

        self::assertSame([(string) $board->id], $this->activityIds($project, search: 'review', family: ActivityFamily::Board));
    }

    public function test_search_wildcards_and_the_escape_character_match_literally(): void
    {
        $project = $this->project('activity-escape@example.com');
        $percent = $this->activity($project, 'test.a%b');
        $underscore = $this->activity($project, 'test.a_b');
        $backslash = $this->activity($project, 'test.a\b');
        $this->activity($project, 'test.axb');
        $this->em->flush();

        self::assertSame([(string) $percent->id], $this->activityIds($project, search: 'a%b'));
        self::assertSame([(string) $underscore->id], $this->activityIds($project, search: 'a_b'));
        self::assertSame([(string) $backslash->id], $this->activityIds($project, search: 'a\b'));
    }

    /** @return list<string> */
    private function activityIds(Project $project, int $page = 1, int $perPage = 20, ?string $search = null, ?ActivityFamily $family = null): array
    {
        return array_map(
            static fn (OutboxEvent $event): string => (string) $event->id,
            array_values(iterator_to_array($this->outboxEvents->findPaginatedForProject($project, $page, $perPage, $search, $family), false)),
        );
    }

    private function activity(Project $project, string $type, string $payload = '{}', string $createdAt = 'now'): OutboxEvent
    {
        $event = new OutboxEvent($project, $type, 'https://app/topic', $payload, new \DateTimeImmutable($createdAt));
        $this->em->persist($event);

        return $event;
    }

    private function inFiveMinutes(?\DateTimeImmutable $from = null): \DateTimeImmutable
    {
        return ($from ?? new \DateTimeImmutable())->add(new \DateInterval('PT5M'));
    }

    private function event(Project $project, string $type = 'test.event'): OutboxEvent
    {
        $event = new OutboxEvent($project, $type, 'https://app/topic', '{}');
        $this->em->persist($event);

        return $event;
    }

    /** @param non-empty-string $email */
    private function project(string $email): Project
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'outbox-'.bin2hex(random_bytes(4)));
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
