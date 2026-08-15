<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Command\CommentNotFound;
use App\Module\SiteReview\Command\DeleteCommentCommand;
use App\Module\SiteReview\Command\DeleteCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteCommentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AddCommentHandler $addHandler;
    private DeleteCommentHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $addHandler = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $addHandler);
        $this->addHandler = $addHandler;
        $handler = self::getContainer()->get(DeleteCommentHandler::class);
        self::assertInstanceOf(DeleteCommentHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_deletes_a_pending_comment(): void
    {
        $project = $this->project('del-a@example.com');
        $comment = ($this->addHandler)(new AddCommentCommand($project, 'to delete', '', '', 'https://app/x'));
        $id = $comment->id ?? throw new \LogicException('comment id must not be null');

        ($this->handler)(new DeleteCommentCommand($project, $id));

        $this->em->clear();
        self::assertNull($this->em->find(SiteReviewComment::class, $id));
    }

    public function test_addressed_comment_throws_comment_not_found(): void
    {
        $project = $this->project('del-b@example.com');
        $comment = ($this->addHandler)(new AddCommentCommand($project, 'orig', '', '', 'https://app/x'));
        $comment->status = SiteReviewCommentStatus::Addressed;
        $this->em->flush();

        $this->expectException(CommentNotFound::class);
        ($this->handler)(new DeleteCommentCommand($project, $comment->id ?? throw new \LogicException('comment id must not be null')));
    }

    public function test_other_sites_comment_throws_comment_not_found(): void
    {
        $siteA = $this->project('del-c@example.com', 'site-a');
        $siteB = $this->project('del-d@example.com', 'site-b');
        $comment = ($this->addHandler)(new AddCommentCommand($siteA, 'orig', '', '', 'https://app/x'));

        $this->expectException(CommentNotFound::class);
        ($this->handler)(new DeleteCommentCommand($siteB, $comment->id ?? throw new \LogicException('comment id must not be null')));
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
