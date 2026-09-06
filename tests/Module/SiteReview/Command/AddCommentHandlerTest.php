<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Command\NewAnchor;
use App\Module\SiteReview\Command\NewStroke;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class AddCommentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AddCommentHandler $handler;
    private RecordingAuditor $audit;

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
        $this->handler = new AddCommentHandler($comments, $this->em, $this->audit->auditor);
    }

    public function test_first_comment_is_pending_at_position_zero(): void
    {
        $project = $this->project('add-a@example.com');
        $comment = ($this->handler)(new AddCommentCommand($project, 'hello', 'https://app/x', [new NewAnchor('.a', 'A')]));

        self::assertNotNull($comment->id);
        self::assertSame(SiteReviewCommentStatus::Pending, $comment->status);
        self::assertSame(0, $comment->position);
    }

    public function test_strokes_are_persisted_and_an_empty_drawing_stores_null(): void
    {
        $project = $this->project('add-strokes@example.com');
        $drawn = ($this->handler)(new AddCommentCommand(
            $project,
            'point here',
            'https://app/x',
            [new NewAnchor('.a', 'A')],
            [new NewStroke('anchor', [[0.25, 0.5], [0.75, 0.5]])],
        ));
        $plain = ($this->handler)(new AddCommentCommand($project, 'no drawing', 'https://app/x'));

        $this->em->clear();
        $reloaded = $this->em->find(SiteReviewComment::class, $drawn->id);
        self::assertNotNull($reloaded);
        self::assertSame(
            [['space' => 'anchor', 'points' => [[0.25, 0.5], [0.75, 0.5]]]],
            $reloaded->strokes,
        );

        $reloadedPlain = $this->em->find(SiteReviewComment::class, $plain->id);
        self::assertNotNull($reloadedPlain);
        self::assertNull($reloadedPlain->strokes);
    }

    public function test_anchors_are_persisted_in_the_order_they_were_given(): void
    {
        $project = $this->project('add-anchors@example.com');

        $comment = ($this->handler)(new AddCommentCommand($project, 'these two', 'https://app/x', [
            new NewAnchor('.first', 'First'),
            new NewAnchor('.second', 'Second', quote: 'a quoted run'),
        ]));
        $this->em->clear();

        $reloaded = $this->em->find(SiteReviewComment::class, $comment->id);
        self::assertNotNull($reloaded);
        $anchors = array_values($reloaded->anchors->toArray());
        self::assertCount(2, $anchors);
        self::assertSame(['.first', '.second'], array_map(static fn ($a) => $a->selector, $anchors));
        self::assertSame([0, 1], array_map(static fn ($a) => $a->position, $anchors));
        self::assertNull($anchors[0]->quote);
        self::assertSame('a quoted run', $anchors[1]->quote);
    }

    public function test_a_comment_with_no_anchor_is_an_unanchored_page_note(): void
    {
        $project = $this->project('add-note@example.com');

        $comment = ($this->handler)(new AddCommentCommand($project, 'a page note', 'https://app/x'));

        self::assertCount(0, $comment->anchors);
        self::assertSame('', $comment->selector);
        self::assertSame('', $comment->text);
    }

    /**
     * The retained columns are written and never read. A previous image maps
     * them as non-nullable, so a rollback onto this schema hydrates a row only
     * when they carry anchor 0. Read them from the database, not from the
     * in-memory entity, because the write is the part that has to hold.
     */
    public function test_a_new_comment_still_writes_anchor_zero_to_the_old_columns(): void
    {
        $project = $this->project('add-scalars@example.com');

        $comment = ($this->handler)(new AddCommentCommand($project, 'these two', 'https://app/x', [
            new NewAnchor('.first', 'First'),
            new NewAnchor('.second', 'Second'),
        ]));

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT selector, text FROM site_review_comments WHERE id = :id',
            ['id' => (string) $comment->id],
        );
        self::assertSame(['selector' => '.first', 'text' => 'First'], $row);
    }

    public function test_a_page_note_writes_empty_strings_to_the_old_columns(): void
    {
        $project = $this->project('add-scalars-note@example.com');

        $comment = ($this->handler)(new AddCommentCommand($project, 'a page note', 'https://app/x'));

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT selector, text FROM site_review_comments WHERE id = :id',
            ['id' => (string) $comment->id],
        );
        self::assertSame(['selector' => '', 'text' => ''], $row);
    }

    public function test_deleting_a_comment_removes_its_anchors(): void
    {
        $project = $this->project('add-cascade@example.com');

        $comment = ($this->handler)(new AddCommentCommand($project, 'doomed', 'https://app/x', [
            new NewAnchor('.a', 'A'),
            new NewAnchor('.b', 'B'),
        ]));
        $commentId = (string) $comment->id;

        $this->em->remove($comment);
        $this->em->flush();

        $left = $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM site_review_comment_anchors WHERE comment_id = :id',
            ['id' => $commentId],
        );
        self::assertSame(0, (int) $left);
    }

    public function test_second_comment_increments_position(): void
    {
        $project = $this->project('add-b@example.com');
        ($this->handler)(new AddCommentCommand($project, 'one', 'https://app/x'));
        $second = ($this->handler)(new AddCommentCommand($project, 'two', 'https://app/y'));

        self::assertSame(1, $second->position);
    }

    public function test_position_keeps_incrementing_after_a_send(): void
    {
        $project = $this->project('add-c@example.com');
        ($this->handler)(new AddCommentCommand($project, 'one', 'https://app/x'));
        $this->em->getConnection()->executeStatement(
            'UPDATE site_review_comments SET status = :status WHERE project_id = :project',
            ['status' => 'pending', 'project' => (string) $project->id],
        );

        // No batch boundary anymore: position is a project-wide monotonic
        // counter, so a comment added after a send simply continues it.
        $next = ($this->handler)(new AddCommentCommand($project, 'two', 'https://app/y'));

        self::assertSame(1, $next->position);
    }

    public function test_an_added_comment_is_recorded_on_the_domain_channel(): void
    {
        $project = $this->project('add-audit@example.com');

        $comment = ($this->handler)(new AddCommentCommand($project, 'hello', 'https://app/x', [new NewAnchor('.a', 'A')]));

        $record = $this->audit->record('site_review.comment_added');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('site_review_comment', $record->subject->type);
        self::assertSame((string) $comment->id, $record->subject->id);
        self::assertSame([
            'projectId' => (string) $project->id,
            'commentId' => (string) $comment->id,
        ], $record->context);

        self::assertSame(['site_review.comment_added'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(AddCommentHandler::class);
    }

    /** @param non-empty-string $email */
    private function project(string $email, string $name = 'handler-site'): Project
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, $name);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
