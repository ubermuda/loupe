<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Scheduler;

use App\Module\Board\Entity\Card;
use App\Module\Workflow\Command\EvaluateDueWorkflowCardsHandler;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Scheduler\EvaluateDueCardsTask;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EvaluateDueCardsTaskTest extends KernelTestCase
{
    use ActionScenario;

    public function test_the_task_is_registered_on_the_default_schedule_every_minute(): void
    {
        self::bootKernel();

        self::assertSame('* * * * *', ScheduledTasks::cronExpressions(self::getContainer())[EvaluateDueCardsTask::class] ?? null);
    }

    public function test_the_task_queues_an_evaluation_of_each_card_with_a_due_retry(): void
    {
        [$due, $later] = $this->scenario();

        $this->task()();

        $sent = $this->sent();
        self::assertContains((string) $due->id, $sent);
        self::assertNotContains((string) $later->id, $sent);
    }

    /** @return array{Card, Card} */
    private function scenario(): array
    {
        self::bootKernel();
        $project = $this->workflowProject('due-cards');
        $due = $this->card($project, 'next');
        $later = $this->card($project, 'next');
        foreach ([[$due, '2026-10-02 11:59:00'], [$later, '2026-10-02 12:01:00']] as [$card, $dueAt]) {
            $state = new WorkflowRuleState($card, $project, 'rule');
            $state->dueAt = new \DateTimeImmutable($dueAt);
            $this->em()->persist($state);
        }
        $this->em()->flush();
        $this->transport()->reset();

        return [$due, $later];
    }

    private function task(): EvaluateDueCardsTask
    {
        return new EvaluateDueCardsTask(new EvaluateDueWorkflowCardsHandler(
            $this->service(WorkflowRuleStateRepository::class),
            new EvaluationTrigger($this->service(MessageBusInterface::class)),
            new MockClock('2026-10-02 12:00:00'),
        ));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<string> */
    private function sent(): array
    {
        return array_values(array_map(
            static fn (EvaluateCard $message): string => $message->cardId,
            array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()), static fn (object $message): bool => $message instanceof EvaluateCard),
        ));
    }
}
