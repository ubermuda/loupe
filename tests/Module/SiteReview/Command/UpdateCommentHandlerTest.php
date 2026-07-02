<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Command\CommentNotFound;
use App\Module\SiteReview\Command\UpdateCommentCommand;
use App\Module\SiteReview\Command\UpdateCommentHandler;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Entity\SiteReviewStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UpdateCommentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AddCommentHandler $addHandler;
    private UpdateCommentHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $addHandler = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $addHandler);
        $this->addHandler = $addHandler;
        $handler = self::getContainer()->get(UpdateCommentHandler::class);
        self::assertInstanceOf(UpdateCommentHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_edits_a_draft_comment_body(): void
    {
        $site = $this->site('upd-a@example.com');
        $comment = ($this->addHandler)(new AddCommentCommand($site, 'orig', '', '', 'https://app/x'));

        $updated = ($this->handler)(new UpdateCommentCommand($site, $comment->id ?? throw new \LogicException('comment id must not be null'), 'edited'));

        self::assertSame('edited', $updated->body);
    }

    public function test_submitted_comment_is_not_editable(): void
    {
        $site = $this->site('upd-b@example.com');
        $comment = ($this->addHandler)(new AddCommentCommand($site, 'orig', '', '', 'https://app/x'));
        $comment->review->status = SiteReviewStatus::Submitted;
        $this->em->flush();

        $this->expectException(CommentNotFound::class);
        ($this->handler)(new UpdateCommentCommand($site, $comment->id ?? throw new \LogicException('comment id must not be null'), 'edited'));
    }

    public function test_other_sites_comment_is_not_found(): void
    {
        $siteA = $this->site('upd-c@example.com', 'site-a');
        $siteB = $this->site('upd-d@example.com', 'site-b');
        $comment = ($this->addHandler)(new AddCommentCommand($siteA, 'orig', '', '', 'https://app/x'));

        $this->expectException(CommentNotFound::class);
        ($this->handler)(new UpdateCommentCommand($siteB, $comment->id ?? throw new \LogicException('comment id must not be null'), 'edited'));
    }

    /** @param non-empty-string $email */
    private function site(string $email, string $name = 'handler-site'): Site
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $site = new Site($user, $name);
        $this->em->persist($site);
        $this->em->flush();

        return $site;
    }
}
