<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Scheduler;

use App\Module\Board\Service\BoardAvailability;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Workflow\Command\SweepWorkflowCardsHandler;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Scheduler\SweepWorkflowCardsTask;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SweepWorkflowCardsTaskTest extends KernelTestCase
{
    use ActionScenario;

    public function test_the_task_is_registered_on_the_default_schedule_every_ten_minutes(): void
    {
        self::bootKernel();

        self::assertSame('*/10 * * * *', ScheduledTasks::cronExpressions(self::getContainer())[SweepWorkflowCardsTask::class] ?? null);
    }

    public function test_the_sweep_queues_the_open_cards_of_bound_projects_and_logs_the_count(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('sweep-bound');
        $this->bindLifecycle($bound);
        $open = $this->card($bound, 'next');
        $backlog = $this->card($bound, 'backlog');
        $finished = $this->card($bound, 'done');
        $held = $this->card($bound, 'next');
        $this->em()->persist(new CardHold($bound, $held->id ?? throw new \LogicException('A flushed card has an id.'), null, new \DateTimeImmutable()));
        $this->em()->flush();
        $unbound = $this->card($this->workflowProject('sweep-unbound'), 'next');
        $this->transport()->reset();
        $logger = new RecordingLogger();

        $this->task($logger)();

        $sent = $this->sent();
        self::assertContains((string) $open->id, $sent);
        self::assertContains((string) $backlog->id, $sent);
        self::assertNotContains((string) $finished->id, $sent);
        self::assertNotContains((string) $held->id, $sent);
        self::assertNotContains((string) $unbound->id, $sent);
        self::assertCount(1, $logger->records);
        self::assertSame('workflow.sweep_finished', $logger->records[0]['message']);
        self::assertSame(\count($sent), $logger->records[0]['context']['cards']);
        self::assertIsInt($logger->records[0]['context']['durationMs']);
    }

    private function task(RecordingLogger $logger): SweepWorkflowCardsTask
    {
        return new SweepWorkflowCardsTask(
            new SweepWorkflowCardsHandler(
                $this->service(WorkflowBindingRepository::class),
                new EvaluationTrigger($this->service(MessageBusInterface::class), $this->service(BoardAvailability::class)),
            ),
            $logger,
        );
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
