<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictDeliveryState;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Board\Entity\SiteReviewCheckState;
use App\Module\Board\Workflow\CheckWanted;
use App\Module\Board\Workflow\SiteReviewFactProvider;
use App\Module\Board\Workflow\SiteReviewFacts;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Module\Board\CardVerdictScenario;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SiteReviewFactProviderTest extends KernelTestCase
{
    use BoardToolScenario;
    use CardVerdictScenario;

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('site-review-facts');
    }

    public function test_a_card_with_no_verdict_and_no_pull_request_has_empty_facts(): void
    {
        $card = $this->card($this->project);
        $this->em->flush();

        $facts = $this->build($card);

        self::assertEquals(new SiteReviewFacts([], [], false), $facts);
        self::assertSame([[], []], $this->provider()->fingerprint($facts));
    }

    public function test_the_provider_is_on_and_names_its_source(): void
    {
        self::assertTrue($this->provider()->isOn());
        self::assertSame(SiteReviewFacts::class, $this->provider()->factsClass());
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertSame('Board', $translator->trans($this->provider()->source()));
    }

    public function test_only_the_pending_deliveries_of_the_card_are_listed(): void
    {
        $card = $this->card($this->project);
        $other = $this->card($this->project, 'next', 2);
        $pending = $this->delivery($card, $this->linkedPullRequest($card, 7), []);
        $posted = $this->delivery($card, $this->linkedPullRequest($card, 8), []);
        $posted->state = CardVerdictDeliveryState::Posted;
        $this->delivery($other, $this->linkedPullRequest($other, 9), []);
        $this->em->flush();

        self::assertSame([(string) $pending->id], $this->build($card)->pendingDeliveryIds);
    }

    public function test_a_pull_request_with_no_check_row_wants_a_check_that_was_never_posted(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $pullRequest->headSha = 'sha-1';
        $this->em->flush();

        $facts = $this->build($card);

        self::assertEquals([(string) $pullRequest->id => new CheckWanted('sha-1', 'success', 0, null, null, null, null, $this->provider()->notesDigest([]), null)], $facts->checks);
    }

    public function test_the_wanted_check_fails_while_a_note_that_a_verdict_carried_is_pending(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $pullRequest->headSha = 'sha-1';
        $pending = $this->note($card, 'Footer overlaps');
        $addressed = $this->note($card, 'Logo is blurry', SiteReviewCommentStatus::Addressed);
        $this->delivery($card, $pullRequest, [$pending, $addressed]);
        $this->em->flush();

        $check = $this->build($card)->checks[(string) $pullRequest->id];

        self::assertSame(['failure', 1], [$check->wantedConclusion, $check->noteCount]);
    }

    public function test_the_notes_of_every_verdict_of_the_card_count(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $pullRequest->headSha = 'sha-1';
        $first = $this->note($card, 'First');
        $second = $this->note($card, 'Second');
        $this->delivery($card, $pullRequest, [$first]);
        $this->delivery($card, $pullRequest, [$second]);
        $this->em->flush();

        self::assertSame(2, $this->build($card)->checks[(string) $pullRequest->id]->noteCount);
    }

    public function test_a_pending_note_that_no_verdict_carried_does_not_count(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $pullRequest->headSha = 'sha-1';
        $this->note($card, 'Written after the verdict');
        $this->delivery($card, $pullRequest, []);
        $this->em->flush();

        $check = $this->build($card)->checks[(string) $pullRequest->id];

        self::assertSame(['success', 0], [$check->wantedConclusion, $check->noteCount]);
    }

    public function test_the_wanted_check_succeeds_once_every_carried_note_leaves_pending(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $pullRequest->headSha = 'sha-1';
        $note = $this->note($card, 'Footer overlaps');
        $this->delivery($card, $pullRequest, [$note]);
        $this->em->flush();
        self::assertSame('failure', $this->build($card)->checks[(string) $pullRequest->id]->wantedConclusion);

        $note->status = SiteReviewCommentStatus::Resolved;
        $this->em->flush();

        self::assertSame('success', $this->build($card)->checks[(string) $pullRequest->id]->wantedConclusion);
    }

    public function test_other_notes_with_the_same_count_give_another_digest_and_fingerprint(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $pullRequest->headSha = 'sha-1';
        $first = $this->note($card, 'Footer overlaps');
        $second = $this->note($card, 'Logo is blurry');
        $this->delivery($card, $pullRequest, [$first]);
        $this->em->flush();
        $before = $this->build($card);

        $first->status = SiteReviewCommentStatus::Resolved;
        $this->delivery($card, $pullRequest, [$second]);
        $this->em->flush();
        $after = $this->build($card);

        $key = (string) $pullRequest->id;
        self::assertSame(1, $before->checks[$key]->noteCount);
        self::assertSame(1, $after->checks[$key]->noteCount);
        self::assertNotSame($before->checks[$key]->notesDigest, $after->checks[$key]->notesDigest);
        self::assertNotSame($this->provider()->fingerprint($before), $this->provider()->fingerprint($after));
    }

    public function test_the_posted_check_and_the_opt_in_are_read(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $pullRequest->headSha = 'sha-2';
        $this->em->persist(new SiteReviewCheckState($pullRequest, 'sha-1', 'failure', 3, 99));
        $this->em->persist(new BoardAutomationSettings($this->project, siteReviewCheck: true));
        $this->em->flush();

        $facts = $this->build($card);

        self::assertEquals([(string) $pullRequest->id => new CheckWanted('sha-2', 'success', 0, 'sha-1', 'failure', 99, 3, $this->provider()->notesDigest([]), null)], $facts->checks);
        self::assertTrue($facts->checkOptedIn);
    }

    public function test_only_open_github_pull_requests_of_the_card_carry_a_check(): void
    {
        $card = $this->card($this->project);
        $open = $this->linkedPullRequest($card, 7);
        $open->headSha = 'sha-1';
        $merged = $this->linkedPullRequest($card, 8, PullRequestState::Merged);
        $merged->headSha = 'sha-2';
        $noHead = $this->linkedPullRequest($card, 9);
        $this->em->flush();

        self::assertSame([(string) $open->id], array_keys($this->build($card)->checks));
        self::assertNull($noHead->headSha);
    }

    public function test_the_fingerprint_changes_when_the_wanted_note_count_changes(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $pullRequest->headSha = 'sha-1';
        $first = $this->note($card, 'Footer overlaps');
        $second = $this->note($card, 'Logo is blurry');
        $this->delivery($card, $pullRequest, [$first, $second]);
        $this->em->flush();
        $before = $this->fingerprint($card);

        $second->status = SiteReviewCommentStatus::Resolved;
        $this->em->flush();

        self::assertSame('failure', $this->build($card)->checks[(string) $pullRequest->id]->wantedConclusion);
        self::assertNotSame($before, $this->fingerprint($card));
    }

    public function test_the_fingerprint_changes_with_the_pending_deliveries_the_head_and_the_wanted_conclusion(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $pullRequest->headSha = 'sha-1';
        $note = $this->note($card, 'Footer overlaps');
        $delivery = $this->delivery($card, $pullRequest, [$note]);
        $this->em->flush();
        $before = $this->fingerprint($card);

        self::assertSame($before, $this->fingerprint($card));
        $delivery->state = CardVerdictDeliveryState::Posted;
        $this->em->flush();
        $afterSettle = $this->fingerprint($card);
        self::assertNotSame($before, $afterSettle);
        $pullRequest->headSha = 'sha-2';
        $this->em->flush();
        $afterPush = $this->fingerprint($card);
        self::assertNotSame($afterSettle, $afterPush);
        $note->status = SiteReviewCommentStatus::Addressed;
        $this->em->flush();
        self::assertNotSame($afterPush, $this->fingerprint($card));
    }

    /** @param list<SiteReviewComment> $notes */
    private function delivery(Card $card, ForgePullRequest $pullRequest, array $notes): CardVerdictDelivery
    {
        $snapshot = array_map(static fn (SiteReviewComment $note): array => ['id' => (string) $note->id, 'url' => 'https://app.example/page', 'body' => 'note', 'anchorCount' => 1], $notes);
        $verdict = new CardVerdict($card, CardVerdictKind::RequestChanges, $this->project->owner, 'Please fix', $snapshot);
        $this->em->persist($verdict);
        $delivery = new CardVerdictDelivery($verdict, $pullRequest);
        $this->em->persist($delivery);

        return $delivery;
    }

    private function build(Card $card): SiteReviewFacts
    {
        $this->em->flush();
        $facts = $this->provider()->build($card->id ?? throw new \LogicException('A flushed card has an id.'));
        self::assertInstanceOf(SiteReviewFacts::class, $facts);

        return $facts;
    }

    private function fingerprint(Card $card): mixed
    {
        $facts = $this->build($card);

        return $this->provider()->fingerprint($facts);
    }

    private function provider(): SiteReviewFactProvider
    {
        $provider = self::getContainer()->get(SiteReviewFactProvider::class);
        self::assertInstanceOf(SiteReviewFactProvider::class, $provider);

        return $provider;
    }
}
