<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Doctrine\ORM\EntityManagerInterface;
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

    public function test_first_comment_is_a_draft_at_position_zero(): void
    {
        $project = $this->project('add-a@example.com');
        $comment = ($this->handler)(new AddCommentCommand($project, 'hello', '.a', 'A', 'https://app/x'));

        self::assertNotNull($comment->id);
        self::assertSame(SiteReviewCommentStatus::Draft, $comment->status);
        self::assertSame(0, $comment->position);
    }

    public function test_second_comment_increments_position(): void
    {
        $project = $this->project('add-b@example.com');
        ($this->handler)(new AddCommentCommand($project, 'one', '', '', 'https://app/x'));
        $second = ($this->handler)(new AddCommentCommand($project, 'two', '', '', 'https://app/y'));

        self::assertSame(1, $second->position);
    }

    public function test_position_keeps_incrementing_after_a_send(): void
    {
        $project = $this->project('add-c@example.com');
        ($this->handler)(new AddCommentCommand($project, 'one', '', '', 'https://app/x'));
        $this->em->getConnection()->executeStatement(
            'UPDATE site_review_comments SET status = :status WHERE project_id = :project',
            ['status' => 'pending', 'project' => (string) $project->id],
        );

        // No batch boundary anymore: position is a project-wide monotonic
        // counter, so a comment added after a send simply continues it.
        $next = ($this->handler)(new AddCommentCommand($project, 'two', '', '', 'https://app/y'));

        self::assertSame(1, $next->position);
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
