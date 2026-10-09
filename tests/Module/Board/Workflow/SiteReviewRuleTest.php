<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictDeliveryState;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Board\Service\ReviewerForgeAccount;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestCheckWriters;
use App\Module\Forge\Service\PullRequestReviewPosters;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Tests\Module\Board\CardVerdictScenario;
use App\Tests\Module\Board\Fake\FakeCheckWriter;
use App\Tests\Module\Board\Fake\FakeReviewerForgeAccount;
use App\Tests\Module\Board\Fake\FakeReviewPoster;
use App\Tests\Module\Workflow\WorkflowProjects;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SiteReviewRuleTest extends KernelTestCase
{
    use CardVerdictScenario;
    use WorkflowProjects;

    private EntityManagerInterface $em;

    #[DataProvider('templates')]
    public function test_a_pending_delivery_fires_the_review_rule_for_each_template(string $template): void
    {
        $this->boot();
        $card = $this->card($this->boundProject($template));
        $verdict = new CardVerdict($card, CardVerdictKind::Comment, $card->project->owner, 'Note', []);
        $this->em()->persist($verdict);
        $this->em()->persist(new CardVerdictDelivery($verdict, $this->linkedPullRequest($card, 7)));
        $this->em()->flush();

        $this->evaluate($card);
        $this->evaluate($card);

        self::assertSame(1, $this->fires($card, 'post-widget-review'));
    }

    public function test_a_second_verdict_after_the_first_review_posts_a_second_review(): void
    {
        $this->boot();
        $card = $this->card($this->boundProject('lifecycle'));
        $pullRequest = $this->linkedPullRequest($card, 7);
        $first = $this->deliveryOf($card, $pullRequest);
        $this->em()->flush();

        // The first pass only baselines the card.
        $this->evaluate($card);
        $this->evaluate($card);
        self::assertSame(CardVerdictDeliveryState::Posted, $first->state);
        // The write queued this evaluation, and it resets the truth of the rule.
        $this->evaluate($card);
        $second = $this->deliveryOf($card, $pullRequest);
        $this->em()->flush();
        $this->evaluate($card);

        self::assertSame(CardVerdictDeliveryState::Posted, $second->state);
    }

    #[DataProvider('templates')]
    public function test_an_open_pull_request_with_no_check_fires_the_check_rule_for_each_template(string $template): void
    {
        $this->boot();
        $card = $this->card($this->boundProject($template));
        $this->linkedPullRequest($card, 7)->headSha = 'sha-1';
        $this->em()->flush();

        $this->evaluate($card);
        $this->evaluate($card);

        self::assertSame(1, $this->fires($card, 'sync-site-review-check'));
        self::assertSame(0, $this->fires($card, 'post-widget-review'));
    }

    public function test_a_card_with_neither_fires_neither_rule(): void
    {
        $this->boot();
        $card = $this->card($this->boundProject('lifecycle'));
        $this->em()->flush();

        $this->evaluate($card);

        self::assertSame([0, 0], [$this->fires($card, 'post-widget-review'), $this->fires($card, 'sync-site-review-check')]);
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        yield 'lifecycle' => ['lifecycle'];
        yield 'simple' => ['simple'];
    }

    private function boot(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->set(PullRequestCheckWriters::class, new PullRequestCheckWriters([new FakeCheckWriter()]));
        $container->set(PullRequestReviewPosters::class, new PullRequestReviewPosters([new FakeReviewPoster()]));
        $container->set(ReviewerForgeAccount::class, new FakeReviewerForgeAccount());
        $this->em = $this->em();
    }

    private function boundProject(string $template): Project
    {
        $project = $this->workflowProject('site-review-rule');
        if ('lifecycle' === $template) {
            $this->bindLifecycle($project);
        } else {
            $this->bindHandler()(new BindWorkflowTemplateCommand($project, $template, []));
        }

        return $project;
    }

    private function deliveryOf(Card $card, ForgePullRequest $pullRequest): CardVerdictDelivery
    {
        $verdict = new CardVerdict($card, CardVerdictKind::Comment, $card->project->owner, 'Note', []);
        $this->em()->persist($verdict);
        $delivery = new CardVerdictDelivery($verdict, $pullRequest);
        $this->em()->persist($delivery);

        return $delivery;
    }

    private function evaluate(Card $card): void
    {
        $engine = self::getContainer()->get(Engine::class);
        self::assertInstanceOf(Engine::class, $engine);
        $engine->evaluate($card->id ?? throw new \LogicException('A flushed card has an id.'), new \DateTimeImmutable('2026-10-08 12:00:00'));
    }

    private function fires(Card $card, string $ruleId): int
    {
        $ruleStates = self::getContainer()->get(WorkflowRuleStateRepository::class);
        self::assertInstanceOf(WorkflowRuleStateRepository::class, $ruleStates);

        $states = $ruleStates->findForCard($card->id ?? throw new \LogicException('A stored card has an id.'));

        return isset($states[$ruleId]) ? $states[$ruleId]->fires : 0;
    }
}
