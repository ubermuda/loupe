<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\Audit\AuditActorProviderInterface;
use App\Module\Audit\AuditEvent;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\MarkSiteReviewCommentAddressedOutcome;
use App\Module\SiteReview\Command\MarkSiteReviewCommentsAddressedCommand;
use App\Module\SiteReview\Command\MarkSiteReviewCommentsAddressedHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MarkSiteReviewCommentsAddressedHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MarkSiteReviewCommentsAddressedHandler $handler;
    private RecordingAuditor $audit;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $comments = self::getContainer()->get(SiteReviewCommentRepository::class);
        self::assertInstanceOf(SiteReviewCommentRepository::class, $comments);

        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        $this->audit = new RecordingAuditor($actors);

        $this->handler = new MarkSiteReviewCommentsAddressedHandler($comments, $this->em, $this->audit->auditor);

        $owner = new User(fullName: 'Owner', email: 'mark-site-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'site-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    public function test_an_addressed_comment_is_recorded_on_the_domain_channel(): void
    {
        $comment = $this->comment();

        ($this->handler)(new MarkSiteReviewCommentsAddressedCommand([$comment]));

        $record = $this->audit->record('site_review.comment.addressed');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('site_review_comment', $record->subject->type);
        self::assertSame((string) $comment->id, $record->subject->id);
        self::assertSame([
            'projectId' => (string) $this->project->id,
            'commentId' => (string) $comment->id,
            'result' => 'Addressed',
        ], $record->context);

        self::assertSame(['site_review.comment.addressed'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /**
     * The record a decorator could not produce. A decorator sees one call and
     * one return value, so it writes one record; the batch decides a different
     * thing per comment and needs one record for each.
     */
    public function test_a_mixed_batch_records_the_outcome_of_every_comment(): void
    {
        $addressable = $this->comment();
        $alreadyAddressed = $this->comment(SiteReviewCommentStatus::Addressed);
        $resolved = $this->comment(SiteReviewCommentStatus::Resolved);

        $outcomes = ($this->handler)(new MarkSiteReviewCommentsAddressedCommand([
            $addressable,
            $alreadyAddressed,
            $resolved,
        ]));

        self::assertSame([
            MarkSiteReviewCommentAddressedOutcome::Addressed,
            MarkSiteReviewCommentAddressedOutcome::AlreadyAddressed,
            MarkSiteReviewCommentAddressedOutcome::AlreadyResolved,
        ], $outcomes);

        $records = $this->audit->records('site_review.comment.addressed');
        self::assertCount(3, $records);
        self::assertSame(
            [AuditOutcome::Success, AuditOutcome::Refused, AuditOutcome::Refused],
            array_map(static fn (AuditEvent $event): AuditOutcome => $event->outcome, $records),
        );
        self::assertSame(
            ['Addressed', 'AlreadyAddressed', 'AlreadyResolved'],
            array_map(static fn (AuditEvent $event): string => (string) $event->context['result'], $records),
        );
        self::assertSame(
            [(string) $addressable->id, (string) $alreadyAddressed->id, (string) $resolved->id],
            array_map(static fn (AuditEvent $event): string => (string) ($event->subject->id ?? ''), $records),
        );
    }

    /** The comment body never reaches the trail. */
    public function test_the_records_carry_no_comment_text(): void
    {
        $comment = $this->comment();

        ($this->handler)(new MarkSiteReviewCommentsAddressedCommand([$comment]));

        $context = $this->audit->record('site_review.comment.addressed')->context;
        self::assertArrayNotHasKey('body', $context);
        self::assertSame([], array_filter(
            $context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value) && str_contains($value, 'Fix this'),
        ));
    }

    /**
     * The MCP tool takes a list of ids and resolves each occurrence to the same
     * row, so one comment can reach the batch twice. Two records against one
     * comment would disagree, because the second occurrence finds it addressed.
     */
    public function test_the_same_comment_named_twice_is_recorded_once(): void
    {
        $comment = $this->comment();

        $outcomes = ($this->handler)(new MarkSiteReviewCommentsAddressedCommand([$comment, $comment]));

        self::assertSame([
            MarkSiteReviewCommentAddressedOutcome::Addressed,
            MarkSiteReviewCommentAddressedOutcome::AlreadyAddressed,
        ], $outcomes);

        $records = $this->audit->records('site_review.comment.addressed');
        self::assertCount(1, $records);
        self::assertSame(AuditOutcome::Success, $records[0]->outcome);
        self::assertSame('Addressed', $records[0]->context['result']);
    }

    public function test_an_empty_batch_records_nothing(): void
    {
        ($this->handler)(new MarkSiteReviewCommentsAddressedCommand([]));

        self::assertSame([], $this->audit->operations());
    }

    private function comment(SiteReviewCommentStatus $status = SiteReviewCommentStatus::Pending): SiteReviewComment
    {
        $comment = new SiteReviewComment($this->project, 0, 'Fix this', '.hero h1', 'Hello world', 'https://example.com/');
        $comment->status = $status;
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }
}
