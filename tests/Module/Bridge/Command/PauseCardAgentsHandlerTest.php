<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\PauseCardAgentsCommand;
use App\Module\Bridge\Command\PauseCardAgentsHandler;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditOutcome;

final class PauseCardAgentsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private ?RecordingAuditor $audit = null;

    public function test_a_pause_holds_the_card_and_writes_the_event(): void
    {
        self::bootKernel();
        $audit = $this->audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $project] = $this->scenario('pause-agents');
        $cardId = Uuid::v7();

        $this->pause($project, $cardId, $owner);

        $this->em()->clear();
        $hold = $this->holds()->findOneOfCard($this->reloaded($project), $cardId);
        self::assertInstanceOf(CardHold::class, $hold);
        self::assertSame((string) $owner->id, (string) $hold->heldBy?->id);

        self::assertSame([[
            'type' => 'board.card_held',
            'subject' => ['type' => 'card', 'id' => (string) $cardId],
            'projectId' => (string) $project->id,
            'actor' => 'human',
        ]], $this->outboxPayloads('board.card_held'));

        $record = $audit->record('bridge.card_held');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame((string) $cardId, $record->subject?->id);
        self::assertSame((string) $project->id, $record->context['projectId']);
        self::assertSame((string) $cardId, $record->context['cardId']);
    }

    public function test_a_pause_of_a_paused_card_is_refused(): void
    {
        self::bootKernel();
        $audit = $this->audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $project] = $this->scenario('pause-agents-twice');
        $cardId = Uuid::v7();
        $this->cardHolds()->hold($project, $cardId, null);

        try {
            $this->pause($project, $cardId, $owner);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['card' => 'bridge.card_hold.error.already_paused'], $e->errors);
        }

        self::assertTrue($this->em()->isOpen());
        self::assertSame([], $this->outboxPayloads('board.card_held'));
        self::assertSame([], $audit->records('bridge.card_held'));
        $hold = $this->holds()->findOneOfCard($project, $cardId);
        self::assertNull($hold?->heldBy);
    }

    public function test_a_pause_holds_one_card_only(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('pause-agents-one');
        $cardId = Uuid::v7();
        $other = Uuid::v7();

        $this->pause($project, $cardId, $owner);

        self::assertTrue($this->cardHolds()->isHeld($project, $cardId));
        self::assertFalse($this->cardHolds()->isHeld($project, $other));
    }

    /** @return array{User, Project} */
    private function scenario(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');

        return [$owner, $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8))];
    }

    private function pause(Project $project, Uuid $cardId, User $by): void
    {
        $this->audit ??= RecordingAuditor::installedIn(self::getContainer());
        $handler = self::getContainer()->get(PauseCardAgentsHandler::class);
        self::assertInstanceOf(PauseCardAgentsHandler::class, $handler);
        $handler(new PauseCardAgentsCommand($project, $cardId, $by));
    }

    private function reloaded(Project $project): Project
    {
        $fresh = $this->em()->find(Project::class, $project->id);
        self::assertInstanceOf(Project::class, $fresh);

        return $fresh;
    }

    private function holds(): CardHoldRepository
    {
        $holds = self::getContainer()->get(CardHoldRepository::class);
        self::assertInstanceOf(CardHoldRepository::class, $holds);

        return $holds;
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
