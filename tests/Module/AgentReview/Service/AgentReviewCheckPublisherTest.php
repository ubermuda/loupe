<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\AgentReview\Service\AgentReviewAnnotations;
use App\Module\AgentReview\Service\AgentReviewCheckPublisher;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\AgentReviewCheck;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Service\PullRequestCheckAnnotation;
use App\Module\Forge\Service\PullRequestCheckAnnotationLevel;
use App\Module\Forge\Service\PullRequestCheckConclusion;
use App\Module\Forge\Service\PullRequestCheckWriters;
use App\Module\Project\Entity\Project;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use App\Tests\Module\Board\Fake\FakeCheckWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AgentReviewCheckPublisherTest extends KernelTestCase
{
    use AgentReviewScenario;

    private EntityManagerInterface $em;
    private Project $project;
    private FakeCheckWriter $writer;
    private int $stored = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('agent-review-check');
        $this->writer = new FakeCheckWriter();
    }

    public function test_the_container_aliases_the_board_port_to_the_publisher(): void
    {
        self::assertInstanceOf(AgentReviewCheckPublisher::class, self::getContainer()->get(AgentReviewCheck::class));
    }

    public function test_each_unposted_review_goes_out_once_on_a_new_run(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $first = $this->stored($card, $pullRequest, str_repeat('1', 40), []);
        $second = $this->stored($card, $pullRequest, str_repeat('2', 40), [$this->finding(AgentReviewSeverity::Important)]);

        $result = $this->publisher()->publish($card);

        self::assertNull($result->failure);
        self::assertTrue($result->changed);
        self::assertSame(
            [['loupe/agent-review', str_repeat('1', 40), PullRequestCheckConclusion::Success, null], ['loupe/agent-review', str_repeat('2', 40), PullRequestCheckConclusion::Failure, null]],
            array_map(static fn (array $call): array => [$call['name'], $call['sha'], $call['conclusion'], $call['runId']], $this->writer->published),
        );
        self::assertSame([101, 102], [$first->checkRunId, $second->checkRunId]);
        self::assertEquals(new \DateTimeImmutable('2026-10-09 12:00:00'), $first->postedAt);

        $this->writer->published = [];
        $again = $this->publisher()->publish($card);

        self::assertSame([], $this->writer->published);
        self::assertFalse($again->changed);
    }

    public function test_a_posted_review_is_skipped(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest, new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        self::assertFalse($this->publisher()->publish($card)->changed);
        self::assertSame([], $this->writer->published);
    }

    public function test_a_refusal_on_one_pull_request_does_not_stop_another(): void
    {
        $card = $this->card($this->project);
        $refused = $this->stored($card, $this->linked($card, 7), str_repeat('a', 40), []);
        $posted = $this->stored($card, $this->linked($card, 8), str_repeat('a', 40), []);
        $this->writer->failingNumbers = [7];

        $result = $this->publisher()->publish($card);

        self::assertSame('permission', $result->failure);
        self::assertTrue($result->changed);
        self::assertNull($refused->postedAt);
        self::assertNull($refused->checkRunId);
        self::assertNotNull($posted->postedAt);
    }

    public function test_a_run_that_took_part_of_the_notes_gets_the_rest_on_the_next_publish(): void
    {
        $card = $this->card($this->project);
        $findings = [];
        for ($line = 1; $line <= 120; ++$line) {
            $findings[] = new AgentReviewFinding('src/Foo.php', $line, $line, AgentReviewSeverity::Nit, 'Note '.$line, 'Fix line '.$line.'.');
        }
        $review = $this->stored($card, $this->linked($card, 7), str_repeat('a', 40), $findings);
        $this->writer->failsAfterAnnotations = 50;

        $first = $this->publisher()->publish($card);
        $second = $this->publisher()->publish($card);

        self::assertSame('server_error', $first->failure);
        self::assertSame('server_error', $second->failure);
        self::assertSame(101, $review->checkRunId);
        self::assertSame(100, $review->annotationsPosted);
        self::assertNull($review->postedAt);

        $third = $this->publisher()->publish($card);

        self::assertNull($third->failure);
        self::assertTrue($third->changed);
        self::assertSame(
            [[null, 1, 120], [101, 51, 70], [101, 101, 20]],
            array_map(static fn (array $call): array => [$call['runId'], $call['annotations'][0]->startLine, \count($call['annotations'])], $this->writer->published),
        );
        $this->em->clear();
        $stored = $this->em->find(AgentReview::class, $review->id);
        self::assertNotNull($stored);
        self::assertSame(101, $stored->checkRunId);
        self::assertSame(120, $stored->annotationsPosted);
        self::assertNotNull($stored->postedAt);
    }

    public function test_a_closed_pull_request_gets_no_check(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $pullRequest->state = PullRequestState::Closed;
        $this->stored($card, $pullRequest, str_repeat('a', 40), []);

        self::assertFalse($this->publisher()->publish($card)->changed);
        self::assertSame([], $this->writer->published);
    }

    public function test_a_project_with_no_installation_posts_nothing_and_reports_no_failure(): void
    {
        $card = $this->card($this->project);
        $review = $this->stored($card, $this->linked($card, 7), str_repeat('a', 40), []);
        $this->writer->failingNumbers = [7];
        $this->writer->failureCause = 'no_installation';

        $result = $this->publisher()->publish($card);

        self::assertNull($result->failure);
        self::assertFalse($result->changed);
        self::assertNull($review->postedAt);
    }

    public function test_each_severity_maps_to_its_annotation_level(): void
    {
        $card = $this->card($this->project);
        $this->stored($card, $this->linked($card, 7), str_repeat('a', 40), [
            $this->finding(AgentReviewSeverity::Important),
            $this->finding(AgentReviewSeverity::Nit),
            new AgentReviewFinding('lib/bar.php', 9, 9, AgentReviewSeverity::PreExisting, 'Old bug', ''),
        ]);

        $this->publisher()->publish($card);

        self::assertEquals([
            new PullRequestCheckAnnotation('src/Foo.php', 3, 5, PullRequestCheckAnnotationLevel::Failure, 'Null read', 'The value can be null here.'),
            new PullRequestCheckAnnotation('src/Foo.php', 3, 5, PullRequestCheckAnnotationLevel::Warning, 'Null read', 'The value can be null here.'),
            new PullRequestCheckAnnotation('lib/bar.php', 9, 9, PullRequestCheckAnnotationLevel::Notice, 'Old bug', 'Old bug'),
        ], $this->writer->published[0]['annotations']);
    }

    public function test_the_title_counts_the_findings_and_the_summary_lists_each_one(): void
    {
        $card = $this->card($this->project);
        $this->stored($card, $this->linked($card, 7), str_repeat('a', 40), [
            $this->finding(AgentReviewSeverity::Important),
            $this->finding(AgentReviewSeverity::Nit),
            new AgentReviewFinding('lib/bar.php', 9, 9, AgentReviewSeverity::Nit, 'Long', str_repeat('word ', 100)),
        ]);
        $clear = $this->card($this->project, 2);
        $this->stored($clear, $this->linked($clear, 8), str_repeat('a', 40), []);

        $this->publisher()->publish($card);
        $this->publisher()->publish($clear);

        [$found, $passed] = $this->writer->published;
        self::assertSame('Agent review: 1 important finding, 2 nits', $found['title']);
        $lines = explode("\n", $found['summary']);
        self::assertSame(['Summary of the review.', '', '- [important] src/Foo.php:3-5 Null read: The value can be null here.', '- [nit] src/Foo.php:3-5 Null read: The value can be null here.'], \array_slice($lines, 0, 4));
        self::assertStringStartsWith('- [nit] lib/bar.php:9 Long: word word', $lines[4]);
        self::assertStringEndsWith('...', $lines[4]);
        self::assertSame(300, mb_strlen($lines[4]) - mb_strlen('- [nit] lib/bar.php:9 Long: '));
        self::assertSame(['Agent review: no findings', 'Summary of the review.'], [$passed['title'], $passed['summary']]);
    }

    public function test_spec_findings_come_first_under_their_own_heading_and_a_finding_with_no_path_has_no_note(): void
    {
        $card = $this->card($this->project);
        $this->stored($card, $this->linked($card, 7), str_repeat('a', 40), [
            $this->finding(AgentReviewSeverity::Nit),
            new AgentReviewFinding(null, null, null, AgentReviewSeverity::Important, 'Requirement not built', 'No size limit.', AgentReviewCategory::Spec),
        ]);

        $this->publisher()->publish($card);

        self::assertSame([
            'Summary of the review.',
            '',
            'Spec: the change against the approved design',
            '- [important] Requirement not built: No size limit.',
            '',
            'Code',
            '- [nit] src/Foo.php:3-5 Null read: The value can be null here.',
        ], explode("\n", $this->writer->published[0]['summary']));
        self::assertCount(1, $this->writer->published[0]['annotations']);
    }

    public function test_the_summary_lists_the_first_findings_and_counts_the_rest(): void
    {
        $card = $this->card($this->project);
        $longest = new AgentReviewFinding(str_repeat('p', 1000), 99999, 100000, AgentReviewSeverity::PreExisting, str_repeat('t', 200), str_repeat('b', 4000));
        $this->em->persist(new AgentReview($this->project, $card, $this->linked($card, 7), str_repeat('a', 40), str_repeat('s', 10000), AgentReviewConclusion::Success, array_fill(0, 200, $longest)));

        $this->publisher()->publish($card);

        $summary = $this->writer->published[0]['summary'];
        $lines = explode("\n", $summary);
        self::assertCount(2 + AgentReviewCheckPublisher::MAX_LISTED_FINDINGS + 1, $lines);
        self::assertSame('And 180 more findings.', array_last($lines));
        self::assertLessThan(50000, mb_strlen($summary));
        self::assertCount(200, $this->writer->published[0]['annotations']);
    }

    private function publisher(): AgentReviewCheckPublisher
    {
        $this->em->flush();
        $reviews = self::getContainer()->get(AgentReviewRepository::class);
        self::assertInstanceOf(AgentReviewRepository::class, $reviews);
        $cardPullRequests = self::getContainer()->get(CardPullRequestRepository::class);
        self::assertInstanceOf(CardPullRequestRepository::class, $cardPullRequests);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return new AgentReviewCheckPublisher(
            $cardPullRequests,
            $reviews,
            new PullRequestCheckWriters([$this->writer]),
            new AgentReviewAnnotations(),
            $translator,
            $this->em,
            new MockClock('2026-10-09 12:00:00'),
        );
    }

    private function linked(Card $card, int $number): ForgePullRequest
    {
        $this->em->persist(new CardPullRequest($card, 'https://github.com/acme/widgets/pull/'.$number, Forge::GitHub, 'acme/widgets', $number));

        return $this->pullRequest($card->project, $number);
    }

    /** @param list<AgentReviewFinding> $findings */
    private function stored(Card $card, ForgePullRequest $pullRequest, string $headSha, array $findings): AgentReview
    {
        $review = new AgentReview(
            project: $card->project,
            card: $card,
            pullRequest: $pullRequest,
            headSha: $headSha,
            summary: 'Summary of the review.',
            conclusion: [] === $findings || AgentReviewSeverity::Important !== $findings[0]->severity ? AgentReviewConclusion::Success : AgentReviewConclusion::Failure,
            findings: $findings,
            createdAt: new \DateTimeImmutable('2026-10-09 10:00:00')->modify(\sprintf('+%d minutes', ++$this->stored)),
        );
        $this->em->persist($review);

        return $review;
    }

    private function finding(AgentReviewSeverity $severity): AgentReviewFinding
    {
        return new AgentReviewFinding('src/Foo.php', 3, 5, $severity, 'Null read', 'The value can be null here.');
    }
}
