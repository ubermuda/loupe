<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Engine\Engine;
use App\Tests\Module\Readiness\DiscoveryScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DiscoveryRuleTest extends KernelTestCase
{
    use DiscoveryScenario;

    #[DataProvider('templates')]
    public function test_a_requested_run_opens_one_discovery_request_that_carries_the_prompt(string $template): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->boundProject($template));
        $this->discoveryRun($card);

        $this->evaluate($card, '2026-10-02 12:00:00');
        $this->evaluate($card, '2026-10-02 12:05:00');

        $requests = $this->requests($card);
        self::assertCount(1, $requests);
        self::assertSame(['discovery', 'discovery', WorkRequestState::Open], [$requests[0]->kind, $requests[0]->ruleId, $requests[0]->state]);
        self::assertSame(file_get_contents(\dirname(__DIR__, 4).'/config/workflows/app/prompts/discovery.md'), $requests[0]->prompt);
    }

    #[DataProvider('templates')]
    public function test_a_failed_run_opens_no_request(string $template): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->boundProject($template));
        $this->discoveryRun($card, DiscoveryRunState::Failed);

        $this->evaluate($card, '2026-10-02 12:00:00');

        self::assertSame([], $this->requests($card));
    }

    public function test_a_card_with_no_run_opens_no_request(): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->boundProject('lifecycle'));

        $this->evaluate($card, '2026-10-02 12:00:00');

        self::assertSame([], $this->requests($card));
    }

    public function test_a_requested_run_outside_the_backlog_opens_no_request(): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->boundProject('lifecycle'), 'done');
        $this->discoveryRun($card);

        $this->evaluate($card, '2026-10-02 12:00:00');

        self::assertNotContains('discovery', array_map(static fn (WorkRequest $request): string => $request->kind, $this->requests($card)));
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        yield 'lifecycle' => ['lifecycle'];
        yield 'simple' => ['simple'];
    }

    private function boundProject(string $template): Project
    {
        $project = $this->workflowProject('discovery-rule');
        if ('lifecycle' === $template) {
            $this->bindLifecycle($project);
        } else {
            $this->bindHandler()(new BindWorkflowTemplateCommand($project, $template, []));
        }

        return $project;
    }

    private function evaluate(Card $card, string $at): void
    {
        $engine = self::getContainer()->get(Engine::class);
        self::assertInstanceOf(Engine::class, $engine);
        $engine->evaluate($card->id ?? throw new \LogicException('A flushed card has an id.'), new \DateTimeImmutable($at));
    }

    /** @return list<WorkRequest> */
    private function requests(Card $card): array
    {
        $repository = self::getContainer()->get(WorkRequestRepository::class);
        self::assertInstanceOf(WorkRequestRepository::class, $repository);

        return array_values($repository->findBy(['subjectId' => $card->id]));
    }
}
