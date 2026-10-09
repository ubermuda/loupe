<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Board\Entity\SiteReviewCheckState;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\SiteReviewCheckStateRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\SiteReviewCheckPublisher;
use App\Module\Board\Workflow\SiteReviewFactProvider;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestCheckConclusion;
use App\Module\Forge\Service\PullRequestCheckWriters;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Module\Board\CardVerdictScenario;
use App\Tests\Module\Board\Fake\FakeCheckWriter;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SiteReviewCheckPublisherTest extends KernelTestCase
{
    use BoardToolScenario;
    use CardVerdictScenario;

    private EntityManagerInterface $em;
    private Project $project;
    private FakeCheckWriter $writer;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('check-publish');
        $this->writer = new FakeCheckWriter();
        $this->em->persist(new BoardAutomationSettings($this->project, siteReviewCheck: true));
        $this->em->flush();
    }

    public function test_a_card_with_no_open_pull_request_posts_nothing(): void
    {
        $card = $this->card($this->project);

        self::assertNull($this->publish($card));

        self::assertSame([], $this->writer->published);
    }

    public function test_a_pull_request_with_no_carried_note_gets_a_successful_check(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->openPullRequest($card, 7, 'sha-1');

        self::assertNull($this->publish($card));

        self::assertCount(1, $this->writer->published);
        $call = $this->writer->published[0];
        self::assertSame(['Loupe site review', 'sha-1', PullRequestCheckConclusion::Success, null], [$call['name'], $call['sha'], $call['conclusion'], $call['runId']]);
        self::assertSame('No open site review notes', $call['title']);
        $state = $this->stateOf($pullRequest);
        self::assertSame(['sha-1', 'success', 0, 101], [$state->headSha, $state->conclusion, $state->noteCount, $state->checkRunId]);
    }

    public function test_a_carried_pending_note_fails_the_check_and_the_summary_lists_it(): void
    {
        $card = $this->card($this->project);
        $this->openPullRequest($card, 7, 'sha-1');
        $note = $this->note($card, 'Footer overlaps');
        $this->verdict($card, 7, [$note]);

        $this->publish($card);

        $call = $this->writer->published[0];
        self::assertSame(PullRequestCheckConclusion::Failure, $call['conclusion']);
        self::assertSame('One site review note is open', $call['title']);
        self::assertSame('- https://app.example/page: Footer overlaps', $call['summary']);
    }

    public function test_a_long_note_is_cut_and_a_long_list_is_capped(): void
    {
        $card = $this->card($this->project);
        $this->openPullRequest($card, 7, 'sha-1');
        $notes = [$this->note($card, str_repeat('word ', 200))];
        for ($i = 1; $i <= 21; ++$i) {
            $notes[] = $this->note($card, 'Note '.$i);
        }
        $this->verdict($card, 7, $notes);

        $this->publish($card);

        $lines = explode("\n", $this->writer->published[0]['summary']);
        self::assertCount(21, $lines);
        self::assertLessThanOrEqual(400, \strlen($lines[0]));
        self::assertStringEndsWith('...', $lines[0]);
        self::assertSame('And 2 more notes.', $lines[20]);
    }

    public function test_an_up_to_date_check_is_not_posted_again(): void
    {
        $card = $this->card($this->project);
        $this->openPullRequest($card, 7, 'sha-1');

        $this->publish($card);
        $this->publish($card);

        self::assertCount(1, $this->writer->published);
    }

    public function test_the_same_head_reuses_the_run_when_the_conclusion_changes(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->openPullRequest($card, 7, 'sha-1');
        $this->publish($card);
        $note = $this->note($card, 'Footer overlaps');
        $this->verdict($card, 7, [$note]);

        $this->publish($card);

        self::assertCount(2, $this->writer->published);
        self::assertSame(101, $this->writer->published[1]['runId']);
        self::assertSame('failure', $this->stateOf($pullRequest)->conclusion);
        self::assertSame(101, $this->stateOf($pullRequest)->checkRunId);
    }

    public function test_a_changed_note_count_posts_again_on_the_same_run(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->openPullRequest($card, 7, 'sha-1');
        $first = $this->note($card, 'Footer overlaps');
        $second = $this->note($card, 'Logo is blurry');
        $this->verdict($card, 7, [$first, $second]);
        $this->publish($card);
        $second->status = SiteReviewCommentStatus::Resolved;

        $this->publish($card);

        self::assertCount(2, $this->writer->published);
        self::assertSame(PullRequestCheckConclusion::Failure, $this->writer->published[1]['conclusion']);
        self::assertSame(101, $this->writer->published[1]['runId']);
        self::assertSame('- https://app.example/page: Footer overlaps', $this->writer->published[1]['summary']);
        self::assertSame(1, $this->stateOf($pullRequest)->noteCount);
    }

    public function test_other_notes_with_the_same_count_post_again_with_the_new_summary(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->openPullRequest($card, 7, 'sha-1');
        $first = $this->note($card, 'Footer overlaps');
        $second = $this->note($card, 'Logo is blurry');
        $this->verdict($card, 7, [$first]);
        $this->publish($card);
        $first->status = SiteReviewCommentStatus::Resolved;
        $this->verdict($card, 7, [$second]);

        $this->publish($card);
        $this->publish($card);

        self::assertCount(2, $this->writer->published);
        self::assertSame('- https://app.example/page: Logo is blurry', $this->writer->published[1]['summary']);
        self::assertSame(1, $this->stateOf($pullRequest)->noteCount);
    }

    public function test_a_new_head_starts_a_new_run(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->openPullRequest($card, 7, 'sha-1');
        $this->publish($card);
        $pullRequest->headSha = 'sha-2';

        $this->publish($card);

        self::assertCount(2, $this->writer->published);
        self::assertNull($this->writer->published[1]['runId']);
        self::assertSame('sha-2', $this->stateOf($pullRequest)->headSha);
        self::assertSame(102, $this->stateOf($pullRequest)->checkRunId);
    }

    public function test_a_row_with_no_run_posts_once_the_opt_in_turns_on(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->openPullRequest($card, 7, 'sha-1');
        $this->optIn(false);
        $this->publish($card);
        $this->optIn(true);

        $this->publish($card);

        self::assertCount(1, $this->writer->published);
        self::assertNull($this->writer->published[0]['runId']);
        self::assertSame(101, $this->stateOf($pullRequest)->checkRunId);
    }

    public function test_an_opt_in_that_is_off_writes_the_state_and_posts_nothing(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->openPullRequest($card, 7, 'sha-1');
        $this->optIn(false);

        self::assertNull($this->publish($card));

        self::assertSame([], $this->writer->published);
        $state = $this->stateOf($pullRequest);
        self::assertSame(['sha-1', 'success', null], [$state->headSha, $state->conclusion, $state->checkRunId]);
    }

    public function test_a_failure_on_one_pull_request_does_not_stop_the_others_and_is_refused(): void
    {
        $card = $this->card($this->project);
        $failing = $this->openPullRequest($card, 7, 'sha-1');
        $other = $this->openPullRequest($card, 8, 'sha-2');
        $this->writer->failingNumbers = [7];

        self::assertSame('permission', $this->publish($card));

        self::assertCount(2, $this->writer->published);
        self::assertNull($this->stateRowOf($failing));
        self::assertSame('sha-2', $this->stateOf($other)->headSha);
    }

    public function test_a_pull_request_with_no_writer_is_left_alone(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->openPullRequest($card, 7, 'sha-1');

        self::assertNull($this->publish($card, writers: false));

        self::assertNull($this->stateRowOf($pullRequest));
    }

    private function optIn(bool $on): void
    {
        $this->service(BoardAutomation::class)->settingsOf($this->project)->siteReviewCheck = $on;
        $this->em->flush();
    }

    private function openPullRequest(Card $card, int $number, string $sha): ForgePullRequest
    {
        $pullRequest = $this->linkedPullRequest($card, $number);
        $pullRequest->headSha = $sha;
        $this->em->flush();

        return $pullRequest;
    }

    /** @param list<SiteReviewComment> $notes */
    private function verdict(Card $card, int $number, array $notes): void
    {
        $verdict = new CardVerdict(
            $card,
            CardVerdictKind::Comment,
            $this->project->owner,
            'Notes',
            array_map(static fn (SiteReviewComment $note): array => ['id' => (string) $note->id, 'url' => 'https://app.example/page', 'body' => $note->body, 'anchorCount' => 1], $notes),
        );
        $this->em->persist($verdict);
        $this->em->persist(new CardVerdictDelivery($verdict, $this->linkedPullRequestRow($card, $number)));
        $this->em->flush();
    }

    private function linkedPullRequestRow(Card $card, int $number): ForgePullRequest
    {
        foreach ($this->service(CardPullRequestRepository::class)->findOpenGitHubForCard($card) as $pullRequest) {
            if ($number === $pullRequest->number) {
                return $pullRequest;
            }
        }

        throw new \LogicException('The pull request is linked.');
    }

    private function stateRowOf(ForgePullRequest $pullRequest): ?SiteReviewCheckState
    {
        return $this->service(SiteReviewCheckStateRepository::class)->findOneByPullRequest($pullRequest);
    }

    private function stateOf(ForgePullRequest $pullRequest): SiteReviewCheckState
    {
        return $this->stateRowOf($pullRequest) ?? throw new \LogicException('The check state is stored.');
    }

    private function publish(Card $card, bool $writers = true): ?string
    {
        $this->em->flush();

        return new SiteReviewCheckPublisher(
            $this->service(CardPullRequestRepository::class),
            $this->service(SiteReviewFactProvider::class),
            $this->service(SiteReviewCheckStateRepository::class),
            $this->service(BoardAutomation::class),
            new PullRequestCheckWriters($writers ? [$this->writer] : []),
            $this->service(TranslatorInterface::class),
            $this->em,
            new MockClock('2026-10-08 12:00:00'),
        )->publish($card)->failure;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
