<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * The genuine race (two requests both finding no in-progress review, then racing to
 * create one) isn't reproducible against dama/doctrine-test-bundle's single-connection
 * transaction — see project-backend's note on wrapInTransaction()'s test limitations.
 * This drives the handler with mocked collaborators to prove the *observable* recovery:
 * the loser's flush() throws, and the handler must re-fetch and reattach rather than 500.
 */
final class AddCommentHandlerConcurrencyTest extends TestCase
{
    public function test_a_concurrent_first_comment_reattaches_to_the_winners_review(): void
    {
        $user = new User('alice', 'Alice A', 'alice@example.com', 'x');
        $project = new Project($user, 'race-project');
        $winnerReview = new SiteReview($project);

        /** @var SiteReviewRepository&Stub $siteReviews */
        $siteReviews = $this->createStub(SiteReviewRepository::class);
        // First call (the pre-check): no in-progress review yet — both requests see this.
        // Second call (after the reset following the losing flush): the winner's row,
        // committed by the other request while this one was racing to create its own.
        $siteReviews->method('findOneInProgress')->willReturnOnConsecutiveCalls(null, $winnerReview);

        $flushCalls = 0;

        /** @var EntityManagerInterface&Stub $em */
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(static function () use (&$flushCalls): void {
            ++$flushCalls;
            if (1 === $flushCalls) {
                throw new UniqueConstraintViolationException(PdoDriverException::new(new \PDOException('duplicate key value violates unique constraint "uniq_site_review_in_progress"')), null);
            }
        });

        /** @var ManagerRegistry&MockObject $registry */
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');

        $handler = new AddCommentHandler($siteReviews, $em, $registry, new NullLogger());

        $comment = $handler(new AddCommentCommand($project, 'hello', '.a', 'A', 'https://app/x'));

        self::assertSame($winnerReview, $comment->review, 'the loser\'s comment must attach to the winner\'s review');
        self::assertCount(1, $winnerReview->comments);
        self::assertSame(2, $flushCalls, 'the handler must retry the flush once after resetting the manager');
    }

    /**
     * Regression: if the winner's review is already gone by the time the loser retries
     * (e.g. it was submitted in between), the handler falls back to starting a fresh
     * review — the same "no review yet" case as the very first check. resetManager()
     * detaches every entity the old EM knew about, including the command's Project, so
     * the handler must resolve a fresh managed reference by id rather than construct
     * `new SiteReview($command->project)` from the stale object — that would hand the
     * new EM's UnitOfWork a Project it has never seen, and the relation has no
     * cascade=persist, so flush() would throw.
     */
    public function test_a_concurrent_first_comment_when_the_winner_review_is_already_gone_reattaches_a_managed_project(): void
    {
        $user = new User('bob', 'Bob B', 'bob@example.com', 'x');
        $project = new Project($user, 'race-project-2');
        $projectId = Uuid::v7();
        // Doctrine assigns the id on persist/flush; the mocked EM doesn't, so it is
        // set here to let the handler resolve a managed reference by id after reset.
        new \ReflectionProperty(Project::class, 'id')->setValue($project, $projectId);

        // A distinct instance stands in for the managed reference getReference()
        // would return from the reset EM — proving the handler builds the retry's
        // SiteReview from THIS object, not the stale $project.
        $managedProject = new Project($user, 'race-project-2');
        new \ReflectionProperty(Project::class, 'id')->setValue($managedProject, $projectId);

        /** @var SiteReviewRepository&Stub $siteReviews */
        $siteReviews = $this->createStub(SiteReviewRepository::class);
        // Both the pre-check and the post-reset retry find nothing: the winner's
        // review was already submitted between the failed flush and this retry.
        $siteReviews->method('findOneInProgress')->willReturn(null);

        $flushCalls = 0;

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(static function () use (&$flushCalls): void {
            ++$flushCalls;
            if (1 === $flushCalls) {
                throw new UniqueConstraintViolationException(PdoDriverException::new(new \PDOException('duplicate key value violates unique constraint "uniq_site_review_in_progress"')), null);
            }
        });
        $em->expects(self::once())
            ->method('getReference')
            ->with(Project::class, $projectId)
            ->willReturn($managedProject);

        /** @var ManagerRegistry&MockObject $registry */
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');

        $handler = new AddCommentHandler($siteReviews, $em, $registry, new NullLogger());

        $comment = $handler(new AddCommentCommand($project, 'hello again', '.b', 'B', 'https://app/y'));

        self::assertSame($managedProject, $comment->review->project, 'the retry must build its SiteReview from the freshly resolved reference, not the stale detached project');
        self::assertSame(2, $flushCalls, 'the handler must retry the flush once after resetting the manager');
    }
}
