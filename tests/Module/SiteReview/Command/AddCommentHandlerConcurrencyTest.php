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
}
