<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Command\SubmitReviewCommand;
use App\Module\SiteReview\Command\SubmitReviewHandler;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Entity\SiteReviewStatus;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use App\Module\SiteReview\Service\SiteReviewTopicBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class SubmitReviewHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private HubInterface&\PHPUnit\Framework\MockObject\MockObject $hub;
    private SubmitReviewHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $reviews = self::getContainer()->get(SiteReviewRepository::class);
        self::assertInstanceOf(SiteReviewRepository::class, $reviews);
        $this->hub = $this->createMock(HubInterface::class);
        $topicBuilder = self::getContainer()->get(SiteReviewTopicBuilder::class);
        self::assertInstanceOf(SiteReviewTopicBuilder::class, $topicBuilder);
        $this->handler = new SubmitReviewHandler($reviews, $this->em, $this->hub, $topicBuilder, new NullLogger());
    }

    public function test_submit_stamps_and_publishes_per_site_topic(): void
    {
        $project = $this->project('submit-a@example.com');
        $review = new SiteReview($project);
        $review->addComment('one', '.a', 'A', 'https://app/x');
        $review->addComment('two', '', '', 'https://app/x');
        $this->em->persist($review);
        $this->em->flush();

        $this->hub->expects($this->once())->method('publish')
            ->with(self::callback(function (Update $update) use ($project, $review): bool {
                $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);

                return str_ends_with((string) $update->getTopics()[0], '/projects/'.$project->id.'/site-reviews')
                    && 'site_review.submitted' === $data['type']
                    && (string) $review->id === $data['reviewId']
                    && $project->name === $data['siteName']
                    && 2 === $data['commentCount']
                    && ['https://app/x'] === $data['urls'];
            }))
            ->willReturn('id');

        $result = ($this->handler)(new SubmitReviewCommand($project));

        self::assertSame(SiteReviewStatus::Submitted, $result->status);
        self::assertNotNull($result->submittedAt);
    }

    public function test_no_draft_review_is_a_domain_error(): void
    {
        $project = $this->project('submit-b@example.com');
        $this->hub->expects($this->never())->method('publish');
        $this->expectException(DomainErrors::class);
        ($this->handler)(new SubmitReviewCommand($project));
    }

    public function test_empty_draft_review_is_a_domain_error(): void
    {
        $project = $this->project('submit-c@example.com');
        $this->em->persist(new SiteReview($project));
        $this->em->flush();

        $this->hub->expects($this->never())->method('publish');
        $this->expectException(DomainErrors::class);
        ($this->handler)(new SubmitReviewCommand($project));
    }

    public function test_hub_failure_does_not_fail_the_submit(): void
    {
        $project = $this->project('submit-d@example.com');
        $review = new SiteReview($project);
        $review->addComment('one', '', '', 'https://app/x');
        $this->em->persist($review);
        $this->em->flush();

        $this->hub->expects($this->once())->method('publish')->willThrowException(new \RuntimeException('hub down'));

        $result = ($this->handler)(new SubmitReviewCommand($project));
        self::assertSame(SiteReviewStatus::Submitted, $result->status);
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
