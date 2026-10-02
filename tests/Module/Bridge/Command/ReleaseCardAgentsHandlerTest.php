<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReleaseCardAgentsCommand;
use App\Module\Bridge\Command\ReleaseCardAgentsHandler;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Project\Entity\Project;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditOutcome;

final class ReleaseCardAgentsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private ?RecordingAuditor $audit = null;

    public function test_a_release_deletes_the_hold_and_writes_the_event(): void
    {
        self::bootKernel();
        $audit = $this->audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $project] = $this->scenario('release-agents');
        $cardId = Uuid::v7();
        $other = Uuid::v7();
        $this->cardHolds()->hold($project, $cardId, $owner);
        $this->cardHolds()->hold($project, $other, $owner);

        $this->release($project, $cardId, $owner);

        self::assertFalse($this->cardHolds()->isHeld($project, $cardId));
        self::assertTrue($this->cardHolds()->isHeld($project, $other));

        self::assertSame([[
            'type' => 'board.card_released',
            'subject' => ['type' => 'card', 'id' => (string) $cardId],
            'projectId' => (string) $project->id,
            'actor' => 'human',
        ]], $this->outboxPayloads('board.card_released'));

        $record = $audit->record('bridge.card_released');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame((string) $cardId, $record->subject?->id);
        self::assertSame((string) $project->id, $record->context['projectId']);
        self::assertSame((string) $cardId, $record->context['cardId']);
    }

    public function test_a_release_of_a_card_with_no_hold_is_refused(): void
    {
        self::bootKernel();
        $audit = $this->audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $project] = $this->scenario('release-agents-none');

        try {
            $this->release($project, Uuid::v7(), $owner);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['card' => 'bridge.card_hold.error.not_paused'], $e->errors);
        }

        self::assertTrue($this->em()->isOpen());
        self::assertSame([], $this->outboxPayloads('board.card_released'));
        self::assertSame([], $audit->records('bridge.card_released'));
    }

    public function test_a_hold_of_another_project_is_not_released(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('release-agents-other');
        $foreign = $this->project($this->em(), $owner, 'Other release project');
        $cardId = Uuid::v7();
        $this->cardHolds()->hold($foreign, $cardId, $owner);

        try {
            $this->release($project, $cardId, $owner);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['card' => 'bridge.card_hold.error.not_paused'], $e->errors);
        }

        self::assertTrue($this->cardHolds()->isHeld($foreign, $cardId));
    }

    /** @return array{User, Project} */
    private function scenario(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');

        return [$owner, $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8))];
    }

    private function release(Project $project, Uuid $cardId, User $by): void
    {
        $outbox = self::getContainer()->get(OutboxWriter::class);
        self::assertInstanceOf(OutboxWriter::class, $outbox);
        $runsChanged = self::getContainer()->get(WorkerRunChangedPublisher::class);
        self::assertInstanceOf(WorkerRunChangedPublisher::class, $runsChanged);
        $this->audit ??= RecordingAuditor::installedIn(self::getContainer());

        // Built by hand, because no controller calls the handler yet and the container drops it.
        $handler = new ReleaseCardAgentsHandler($this->cardHolds(), $outbox, $runsChanged, $this->em(), $this->audit->auditor);
        $handler(new ReleaseCardAgentsCommand($project, $cardId, $by));
    }

    private function cardHolds(): CardHolds
    {
        $cardHolds = self::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $cardHolds);

        return $cardHolds;
    }

    /** @return list<array<string, mixed>> */
    private function outboxPayloads(string $type): array
    {
        /** @var list<string> $payloads */
        $payloads = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT payload FROM outbox_events WHERE type = ? ORDER BY sequence',
            [$type],
        );

        return array_map(static fn (string $payload): array => json_decode($payload, true, flags: \JSON_THROW_ON_ERROR), $payloads);
    }
}
