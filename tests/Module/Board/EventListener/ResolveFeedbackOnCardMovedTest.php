<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\EventListener\ResolveFeedbackOnCardMoved;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\CardFeedbackResolver;
use App\Module\Board\Service\CardMove;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\ResolveSiteReviewCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditEvent;

/** Driven through a real move, so the listener runs where UpdateCardHandler dispatches CardMoved. */
final class ResolveFeedbackOnCardMovedTest extends KernelTestCase
{
    use ResolveFeedbackScenario;

    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_a_move_to_done_resolves_pending_and_addressed_feedback_and_leaves_resolved_alone(): void
    {
        $project = $this->feedbackProject('feedback-done');
        $card = $this->cardIn($project, 'in-progress');
        $pending = $this->feedback($card, SiteReviewCommentStatus::Pending);
        $addressed = $this->feedback($card, SiteReviewCommentStatus::Addressed);
        $resolved = $this->feedback($card, SiteReviewCommentStatus::Resolved);
        $this->em->flush();

        $this->move($card, $project, 'done', CardReporter::Agent);

        self::assertSame(SiteReviewCommentStatus::Resolved, $this->statusOf($pending));
        self::assertSame(SiteReviewCommentStatus::Resolved, $this->statusOf($addressed));
        self::assertSame(SiteReviewCommentStatus::Resolved, $this->statusOf($resolved));

        $records = $this->audit->records('site_review.comment_resolved');
        $ids = array_map(static fn (AuditEvent $record): mixed => $record->context['commentId'], $records);
        sort($ids);
        $expected = [(string) $pending->id, (string) $addressed->id];
        sort($expected);
        self::assertSame($expected, $ids);
        foreach ($records as $record) {
            self::assertSame('card_moved', $record->context['trigger']);
            self::assertSame('agent', $record->context['actor']);
        }
    }

    public function test_a_move_back_out_of_done_leaves_the_feedback_resolved(): void
    {
        $project = $this->feedbackProject('feedback-back');
        $card = $this->cardIn($project, 'in-progress');
        $comment = $this->feedback($card, SiteReviewCommentStatus::Pending);
        $this->em->flush();

        $this->move($card, $project, 'done');
        $this->em->clear();
        $card = $this->em->find(Card::class, $card->id);
        self::assertInstanceOf(Card::class, $card);
        $this->move($card, $project, 'in-progress');

        self::assertSame(SiteReviewCommentStatus::Resolved, $this->statusOf($comment));
        self::assertCount(1, $this->audit->records('site_review.comment_resolved'));
    }

    public function test_a_move_between_two_terminal_columns_resolves_nothing(): void
    {
        $project = $this->feedbackProject('feedback-terminal');
        $card = $this->cardIn($project, 'done');
        $comment = $this->feedback($card, SiteReviewCommentStatus::Addressed);
        $this->em->flush();

        $this->move($card, $project, 'shipped');

        self::assertSame(SiteReviewCommentStatus::Addressed, $this->statusOf($comment));
        $moved = $this->em->find(Card::class, $card->id);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('shipped', $moved->column->slug);
        self::assertSame([], $this->audit->records('site_review.comment_resolved'));
    }

    public function test_a_move_between_open_columns_resolves_nothing(): void
    {
        $project = $this->feedbackProject('feedback-open');
        $card = $this->cardIn($project, 'backlog');
        $comment = $this->feedback($card, SiteReviewCommentStatus::Pending);
        $this->em->flush();

        $this->move($card, $project, 'in-progress');

        self::assertSame(SiteReviewCommentStatus::Pending, $this->statusOf($comment));
        self::assertSame([], $this->audit->records('site_review.comment_resolved'));
    }

    public function test_a_failure_in_the_listener_does_not_break_the_move(): void
    {
        $links = $this->createStub(CardSiteReviewCommentRepository::class);
        $links->method('findUnresolvedForCards')->willThrowException(new \RuntimeException('boom'));
        self::getContainer()->set(CardSiteReviewCommentRepository::class, $links);

        $project = $this->feedbackProject('feedback-failure');
        $card = $this->cardIn($project, 'in-progress');
        $this->em->flush();

        $this->move($card, $project, 'done');

        $this->em->clear();
        $moved = $this->em->find(Card::class, $card->id);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('done', $moved->column->slug);
    }

    public function test_a_failure_in_the_listener_is_logged(): void
    {
        $project = $this->feedbackProject('feedback-failure-log');
        $card = $this->cardIn($project, 'done');
        $this->em->flush();

        $links = $this->createStub(CardSiteReviewCommentRepository::class);
        $links->method('findUnresolvedForCards')->willThrowException(new \RuntimeException('boom'));
        $resolve = self::getContainer()->get(ResolveSiteReviewCommentHandler::class);
        self::assertInstanceOf(ResolveSiteReviewCommentHandler::class, $resolve);
        $logger = new RecordingLogger();

        new ResolveFeedbackOnCardMoved(new CardFeedbackResolver($links, $resolve), $logger)(
            new CardMoved($card, new CardMove($this->column($project, 'in-progress')), CardReporter::Human),
        );

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame('board.feedback_resolve_failed', $logger->records[0]['message']);
        self::assertSame((string) $card->id, $logger->records[0]['context']['cardId']);
    }

    private function move(Card $card, Project $project, string $slug, CardReporter $actor = CardReporter::Human): void
    {
        $handler = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $handler);

        $handler(new UpdateCardCommand($card, $actor, column: $this->column($project, $slug)));
    }
}
