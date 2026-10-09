<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\NeutralizeSiteReviewCheckCommand;
use App\Module\Board\Command\NeutralizeSiteReviewCheckHandler;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\SiteReviewCheckStateRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\SiteReviewCheckPublisher;
use App\Module\Board\Workflow\SiteReviewFactProvider;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestCheckConclusion;
use App\Module\Forge\Service\PullRequestCheckWriter;
use App\Module\Forge\Service\PullRequestCheckWriters;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Board\Fake\FakeCheckWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NeutralizeSiteReviewCheckHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private FakeCheckWriter $writer;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new \App\Module\Account\Entity\User(fullName: 'Riley', email: 'neutralize-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);
        $this->project = new Project($owner, 'neutralize-'.uniqid());
        $em->persist($this->project);
        $this->seedColumns($this->project);
        $em->flush();
        $this->writer = new FakeCheckWriter();
    }

    public function test_it_turns_the_posted_run_neutral(): void
    {
        ($this->handler())($this->command());

        self::assertCount(1, $this->writer->published);
        self::assertSame([PullRequestCheckConclusion::Neutral, 55, 'sha-1'], [$this->writer->published[0]['conclusion'], $this->writer->published[0]['runId'], $this->writer->published[0]['sha']]);
    }

    public function test_a_pull_request_that_a_card_links_again_keeps_its_check(): void
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);
        $handler(new CreateCardCommand(project: $this->project, title: 'A card', body: 'Body', type: 'feature', pullRequestUrls: ['https://github.com/acme/widgets/pull/9']));

        ($this->handler())($this->command());

        self::assertSame([], $this->writer->published);
    }

    public function test_a_card_that_links_the_pull_request_during_the_write_is_evaluated_again(): void
    {
        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $project = $this->project;
        $linked = null;
        $racingWriter = new readonly class(static function () use ($create, $project, &$linked): void {
            $linked = $create(new CreateCardCommand(project: $project, title: 'A card', body: 'Body', type: 'feature', pullRequestUrls: ['https://github.com/acme/widgets/pull/9']));
        }) implements PullRequestCheckWriter {
            public function __construct(
                private \Closure $onPublish,
            ) {
            }

            public function supports(string $forge): bool
            {
                return true;
            }

            public function publish(ForgePullRequest $pullRequest, string $name, string $sha, PullRequestCheckConclusion $conclusion, string $title, string $summary, ?int $runId): int
            {
                ($this->onPublish)();

                return $runId ?? 1;
            }
        };
        $evaluations = new class implements CardEvaluations {
            /** @var list<string|Uuid> */
            public array $asked = [];

            public function forCards(array $cardIds): void
            {
                array_push($this->asked, ...$cardIds);
            }

            public function isOn(): bool
            {
                return true;
            }
        };

        ($this->handler($evaluations, $racingWriter))($this->command());

        self::assertNotNull($linked);
        self::assertSame([$linked->id], $evaluations->asked);
    }

    public function test_a_retryable_refusal_asks_for_a_retry(): void
    {
        $this->writer->failingNumbers = [9];
        $this->writer->failsForGood = false;

        $this->expectException(RecoverableMessageHandlingException::class);

        ($this->handler())($this->command());
    }

    public function test_a_permanent_refusal_ends_the_message(): void
    {
        $this->writer->failingNumbers = [9];

        ($this->handler())($this->command());

        self::assertCount(1, $this->writer->published);
    }

    public function test_a_deleted_project_ends_the_message(): void
    {
        ($this->handler())(new NeutralizeSiteReviewCheckCommand(Uuid::v7(), 'github', 'acme/widgets', 9, 'sha-1', 55));

        self::assertSame([], $this->writer->published);
    }

    private function command(): NeutralizeSiteReviewCheckCommand
    {
        return new NeutralizeSiteReviewCheckCommand($this->project->id ?? throw new \LogicException('A persisted project has an id.'), 'github', 'acme/widgets', 9, 'sha-1', 55);
    }

    private function handler(?CardEvaluations $evaluations = null, ?PullRequestCheckWriter $writer = null): NeutralizeSiteReviewCheckHandler
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $clock = $container->get(ClockInterface::class);
        $translator = $container->get(TranslatorInterface::class);
        $projects = $container->get(ProjectRepository::class);
        $automation = $container->get(BoardAutomation::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        self::assertInstanceOf(BoardAutomation::class, $automation);

        return new NeutralizeSiteReviewCheckHandler(
            $projects,
            $container->get(CardPullRequestRepository::class),
            new SiteReviewCheckPublisher(
                $container->get(CardPullRequestRepository::class),
                $container->get(SiteReviewFactProvider::class),
                $container->get(SiteReviewCheckStateRepository::class),
                $automation,
                new PullRequestCheckWriters([$writer ?? $this->writer]),
                $translator,
                $em,
                $clock,
            ),
            $evaluations ?? new class implements CardEvaluations {
                public function forCards(array $cardIds): void
                {
                }

                public function isOn(): bool
                {
                    return true;
                }
            },
            new NullLogger(),
        );
    }
}
