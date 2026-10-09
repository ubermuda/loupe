<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Workflow\AgentReviewFactProvider;
use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\AgentReview\Workflow\ReviewedHead;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AgentReviewFactProviderTest extends KernelTestCase
{
    use AgentReviewScenario;

    private EntityManagerInterface $em;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('agent-review-facts');
    }

    public function test_the_provider_is_on_and_names_its_source(): void
    {
        self::assertTrue($this->provider()->isOn());
        self::assertSame(AgentReviewFacts::class, $this->provider()->factsClass());
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertSame('Agent review', $translator->trans($this->provider()->source()));
    }

    public function test_a_card_with_no_pull_request_has_no_heads_and_the_switch_off_by_default(): void
    {
        $card = $this->card($this->project);
        $this->em->flush();

        self::assertEquals(new AgentReviewFacts([], false, false, false), $this->build($card));
    }

    public function test_an_open_pull_request_head_without_a_review_has_no_conclusion(): void
    {
        $this->switchOn();
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);

        $facts = $this->build($card);

        self::assertTrue($facts->enabled);
        self::assertEquals([new ReviewedHead((string) $pullRequest->id, str_repeat('a', 40), null)], $facts->heads);
    }

    public function test_the_head_carries_the_conclusion_of_its_newest_review(): void
    {
        $this->switchOn();
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);

        self::assertSame(AgentReviewConclusion::Failure, $this->build($card)->heads[0]->conclusion);
    }

    public function test_a_review_of_an_older_head_does_not_count(): void
    {
        $this->switchOn();
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);
        $pullRequest->headSha = str_repeat('f', 40);

        self::assertNull($this->build($card)->heads[0]->conclusion);
    }

    public function test_only_open_pull_requests_with_a_head_are_listed(): void
    {
        $this->switchOn();
        $card = $this->card($this->project);
        $open = $this->linked($card, 7);
        $this->linked($card, 8, PullRequestState::Merged);
        $this->linked($card, 9, headSha: null);

        self::assertSame([(string) $open->id], array_map(static fn (ReviewedHead $head): string => $head->pullRequestId, $this->build($card)->heads));
    }

    public function test_a_review_that_no_check_shows_is_unposted_only_with_the_switch_on(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $this->review($card, $pullRequest);

        self::assertFalse($this->build($card)->unposted);

        $this->switchOn();
        self::assertTrue($this->build($card)->unposted);

        $this->em->getConnection()->executeStatement('UPDATE agent_reviews SET posted_at = NOW()');
        $this->em->clear();
        self::assertFalse($this->build($this->em->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.'))->unposted);
    }

    public function test_a_card_type_with_children_is_an_epic(): void
    {
        $card = new Card($this->project, $this->column($this->project, 'backlog'), 'Epic', '', 3, type: 'epic');
        $this->em->persist($card);

        self::assertTrue($this->build($card)->epic);
        self::assertFalse($this->build($this->card($this->project, 4))->epic);
    }

    public function test_the_fingerprint_changes_with_the_switch_the_head_and_the_conclusion(): void
    {
        $card = $this->card($this->project);
        $pullRequest = $this->linked($card, 7);
        $off = $this->fingerprint($card);

        $this->switchOn();
        $unreviewed = $this->fingerprint($card);
        $this->review($card, $pullRequest);
        $reviewed = $this->fingerprint($card);
        $pullRequest->headSha = str_repeat('f', 40);
        $moved = $this->fingerprint($card);

        self::assertCount(4, array_unique(array_map(serialize(...), [$off, $unreviewed, $reviewed, $moved])));
        self::assertSame($moved, $this->fingerprint($card));
    }

    private function switchOn(): void
    {
        $this->em->persist(new BoardAutomationSettings($this->project, agentReview: true));
        $this->em->flush();
    }

    private function linked(Card $card, int $number, PullRequestState $state = PullRequestState::Open, ?string $headSha = 'default'): ForgePullRequest
    {
        $pullRequest = $this->pullRequest($this->project, $number);
        $pullRequest->state = $state;
        if ('default' !== $headSha) {
            $pullRequest->headSha = $headSha;
        }
        $this->em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/'.$number, Forge::GitHub, 'Acme/Widgets', $number));

        return $pullRequest;
    }

    private function build(Card $card): AgentReviewFacts
    {
        $this->em->flush();
        $facts = $this->provider()->build($card->snapshot());
        self::assertInstanceOf(AgentReviewFacts::class, $facts);

        return $facts;
    }

    private function fingerprint(Card $card): mixed
    {
        return $this->provider()->fingerprint($this->build($card));
    }

    private function provider(): AgentReviewFactProvider
    {
        $provider = self::getContainer()->get(AgentReviewFactProvider::class);
        self::assertInstanceOf(AgentReviewFactProvider::class, $provider);

        return $provider;
    }
}
