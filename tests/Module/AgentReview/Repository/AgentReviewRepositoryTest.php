<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Repository;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AgentReviewRepositoryTest extends KernelTestCase
{
    use AgentReviewScenario;

    private EntityManagerInterface $em;

    private AgentReviewRepository $reviews;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $reviews = self::getContainer()->get(AgentReviewRepository::class);
        self::assertInstanceOf(AgentReviewRepository::class, $reviews);
        $this->reviews = $reviews;
    }

    public function test_find_unposted_answers_the_unposted_reviews_of_the_named_pull_requests_only(): void
    {
        $project = $this->makeProject('agent-review-unposted');
        $card = $this->card($project);
        $named = $this->pullRequest($project, 7);
        $other = $this->pullRequest($project, 8);
        $first = $this->review($card, $named);
        $posted = $this->review($card, $named, new \DateTimeImmutable());
        $elsewhere = $this->review($card, $other);
        $this->em->flush();

        self::assertSame([$first], $this->reviews->findUnpostedForPullRequests([$named]));
        $both = $this->reviews->findUnpostedForPullRequests([$named, $other]);
        self::assertCount(2, $both);
        self::assertContains($first, $both);
        self::assertContains($elsewhere, $both);
        self::assertNotContains($posted, $both);
    }

    public function test_find_unposted_answers_nothing_for_no_pull_request(): void
    {
        self::assertSame([], $this->reviews->findUnpostedForPullRequests([]));
    }

    public function test_the_findings_survive_a_round_trip_through_the_database(): void
    {
        $project = $this->makeProject('agent-review-round-trip');
        $review = $this->review($this->card($project), $this->pullRequest($project));
        $this->em->flush();
        $id = $review->id;
        $this->em->clear();

        $stored = $this->em->find(AgentReview::class, $id);
        self::assertInstanceOf(AgentReview::class, $stored);
        $findings = $stored->findings();
        self::assertCount(1, $findings);
        self::assertSame('src/Foo.php', $findings[0]->path);
        self::assertSame(3, $findings[0]->startLine);
        self::assertSame(5, $findings[0]->endLine);
        self::assertSame(AgentReviewSeverity::Important, $findings[0]->severity);
        self::assertSame('Null read', $findings[0]->title);
        self::assertSame('The value can be null here.', $findings[0]->body);
    }

    public function test_delete_by_project_spares_the_reviews_of_another_project(): void
    {
        $doomed = $this->makeProject('agent-review-doomed');
        $spared = $this->makeProject('agent-review-spared');
        $this->review($this->card($doomed), $this->pullRequest($doomed));
        $kept = $this->review($this->card($spared), $this->pullRequest($spared));
        $this->em->flush();

        self::assertSame(1, $this->reviews->deleteByProject($doomed));

        $this->em->clear();
        self::assertSame(0, $this->reviews->count(['project' => $doomed->id]));
        self::assertNotNull($this->em->find(AgentReview::class, $kept->id));
    }
}
