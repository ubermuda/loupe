<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Entity\SiteReviewStatus;
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

    public function test_first_comment_opens_a_review(): void
    {
        $site = $this->site('add-a@example.com');
        $comment = ($this->handler)(new AddCommentCommand($site, 'hello', '.a', 'A', 'https://app/x'));

        self::assertNotNull($comment->id);
        self::assertSame(SiteReviewStatus::InProgress, $comment->review->status);
        self::assertSame(0, $comment->position);
    }

    public function test_second_comment_reuses_the_open_review(): void
    {
        $site = $this->site('add-b@example.com');
        $first = ($this->handler)(new AddCommentCommand($site, 'one', '', '', 'https://app/x'));
        $second = ($this->handler)(new AddCommentCommand($site, 'two', '', '', 'https://app/y'));

        self::assertSame((string) $first->review->id, (string) $second->review->id);
        self::assertSame(1, $second->position);
    }

    public function test_comment_after_submit_opens_a_new_review(): void
    {
        $site = $this->site('add-c@example.com');
        $first = ($this->handler)(new AddCommentCommand($site, 'one', '', '', 'https://app/x'));
        $first->review->status = SiteReviewStatus::Submitted;
        $first->review->submittedAt = new \DateTimeImmutable();
        $this->em->flush();

        $next = ($this->handler)(new AddCommentCommand($site, 'two', '', '', 'https://app/y'));

        self::assertNotSame((string) $first->review->id, (string) $next->review->id);
        self::assertSame(0, $next->position);
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
