<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewStatus;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AddCommentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AddCommentHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $handler = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_first_comment_opens_a_review(): void
    {
        $project = $this->project('add-a@example.com');
        $comment = ($this->handler)(new AddCommentCommand($project, 'hello', '.a', 'A', 'https://app/x'));

        self::assertNotNull($comment->id);
        self::assertSame(SiteReviewStatus::InProgress, $comment->review->status);
        self::assertSame(0, $comment->position);
    }

    public function test_second_comment_reuses_the_open_review(): void
    {
        $project = $this->project('add-b@example.com');
        $first = ($this->handler)(new AddCommentCommand($project, 'one', '', '', 'https://app/x'));
        $second = ($this->handler)(new AddCommentCommand($project, 'two', '', '', 'https://app/y'));

        self::assertSame((string) $first->review->id, (string) $second->review->id);
        self::assertSame(1, $second->position);
    }

    public function test_comment_after_submit_opens_a_new_review(): void
    {
        $project = $this->project('add-c@example.com');
        $first = ($this->handler)(new AddCommentCommand($project, 'one', '', '', 'https://app/x'));
        $first->review->markSubmitted();
        $this->em->flush();

        $next = ($this->handler)(new AddCommentCommand($project, 'two', '', '', 'https://app/y'));

        self::assertNotSame((string) $first->review->id, (string) $next->review->id);
        self::assertSame(0, $next->position);
    }

    /**
     * Empirical check for the concurrency recovery relied on in AddCommentHandler's
     * catch block: a closed EM (as flush() leaves it after a DBAL exception) must
     * become usable again after ManagerRegistry::resetManager(), because the
     * entity_manager service is declared lazy — resetManager() reinitializes the
     * SAME injected instance in place rather than swapping in a new one. This
     * drives that against the real container/DB rather than trusting the reading
     * of vendored Doctrine ORM/bundle source alone.
     */
    public function test_reset_manager_recovers_a_closed_entity_manager_in_place(): void
    {
        $project = $this->project('reset-manager@example.com');

        $registry = self::getContainer()->get(ManagerRegistry::class);
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $siteReviews = self::getContainer()->get(SiteReviewRepository::class);
        self::assertInstanceOf(SiteReviewRepository::class, $siteReviews);

        $this->em->close();
        self::assertFalse($this->em->isOpen(), 'the EM must actually be closed to exercise the recovery path');

        $registry->resetManager();

        // Must not throw "EntityManager is closed" — and must run a real query
        // through the same injected repository AddCommentHandler uses.
        $found = $siteReviews->findOneInProgress($project);
        self::assertNull($found, 'no review exists yet for this project');
    }

    /** @param non-empty-string $email */
    private function project(string $email, string $name = 'handler-site'): Project
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, $name);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
