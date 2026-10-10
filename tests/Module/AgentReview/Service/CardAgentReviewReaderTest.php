<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Service\CardAgentReviews;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardAgentReviewReaderTest extends KernelTestCase
{
    use AgentReviewScenario;

    private EntityManagerInterface $em;

    private CardAgentReviews $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $reader = self::getContainer()->get(CardAgentReviews::class);
        self::assertInstanceOf(CardAgentReviews::class, $reader);
        $this->reader = $reader;
    }

    public function test_the_newest_review_of_a_pull_request_wins(): void
    {
        $project = $this->makeProject('agent-review-reader-newest');
        $card = $this->card($project);
        $forge = $this->pullRequest($project, 7);
        $link = $this->link($card, 'Acme/Widgets', 7);
        $this->timedReview($card, $forge, new \DateTimeImmutable('2026-10-01T10:00:00+00:00'), 'old');
        $newest = $this->timedReview($card, $forge, new \DateTimeImmutable('2026-10-02T10:00:00+00:00'), 'new');
        $this->em->flush();

        $summaries = $this->reader->forCards([$card]);

        self::assertCount(1, $summaries);
        $summary = $summaries[(string) $link->id];
        self::assertSame((string) $newest->id, $summary['reviewId']);
        self::assertSame('new', $summary['summary']);
        self::assertSame('failure', $summary['conclusion']);
        self::assertSame(str_repeat('a', 40), $summary['headSha']);
        self::assertSame('2026-10-02T10:00:00+00:00', $summary['createdAt']);
        self::assertNull($summary['postedAt']);
        self::assertSame([['path' => 'src/Foo.php', 'startLine' => 3, 'endLine' => 5, 'severity' => 'important', 'title' => 'Null read', 'body' => 'The value can be null here.', 'category' => 'code']], $summary['findings']);
    }

    public function test_a_pull_request_with_no_review_has_no_entry(): void
    {
        $project = $this->makeProject('agent-review-reader-none');
        $card = $this->card($project);
        $this->pullRequest($project, 7);
        $this->link($card, 'acme/widgets', 7);
        $this->em->flush();

        self::assertSame([], $this->reader->forCards([$card]));
    }

    public function test_a_link_whose_forge_row_is_missing_has_no_entry(): void
    {
        $project = $this->makeProject('agent-review-reader-missing');
        $card = $this->card($project);
        $this->link($card, 'acme/widgets', 99);
        $this->em->flush();

        self::assertSame([], $this->reader->forCards([$card]));
    }

    public function test_a_review_of_another_card_on_the_same_pull_request_is_not_shown(): void
    {
        $project = $this->makeProject('agent-review-reader-other-card');
        $card = $this->card($project);
        $other = $this->card($project, 2);
        $forge = $this->pullRequest($project, 7);
        $this->link($card, 'acme/widgets', 7);
        $this->review($other, $forge);
        $this->em->flush();

        self::assertSame([], $this->reader->forCards([$card]));
    }

    public function test_many_cards_are_answered_together(): void
    {
        $project = $this->makeProject('agent-review-reader-many');
        $first = $this->card($project);
        $second = $this->card($project, 2);
        $forge = $this->pullRequest($project, 7);
        $firstLink = $this->link($first, 'acme/widgets', 7);
        $secondLink = $this->link($second, 'acme/widgets', 7);
        $this->review($first, $forge);
        $this->review($second, $forge);
        $this->em->flush();

        $summaries = $this->reader->forCards([$first, $second]);

        self::assertSame([(string) $firstLink->id, (string) $secondLink->id], array_keys($summaries));
    }

    private function link(Card $card, string $repository, int $number): CardPullRequest
    {
        $link = new CardPullRequest($card, 'https://github.com/'.$repository.'/pull/'.$number, Forge::GitHub, $repository, $number);
        $card->pullRequests->add($link);
        $this->em->persist($link);

        return $link;
    }

    private function timedReview(Card $card, ForgePullRequest $forge, \DateTimeImmutable $createdAt, string $summary): AgentReview
    {
        $review = new AgentReview(
            project: $card->project,
            card: $card,
            pullRequest: $forge,
            headSha: str_repeat('a', 40),
            summary: $summary,
            conclusion: AgentReviewConclusion::Failure,
            findings: [new AgentReviewFinding('src/Foo.php', 3, 5, AgentReviewSeverity::Important, 'Null read', 'The value can be null here.')],
            createdAt: $createdAt,
        );
        $this->em->persist($review);

        return $review;
    }
}
