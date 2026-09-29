<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ResolveExperimentPinCommand;
use App\Module\Bridge\Command\ResolveExperimentPinHandler;
use App\Module\Bridge\Command\ResolveExperimentPinResult;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ResolveExperimentPinHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string OLD = '2026-09-01T08:00:00+00:00';

    private const string NOW = '2026-09-29T12:00:00+00:00';

    public function test_the_first_run_of_a_card_pins_the_candidate(): void
    {
        $this->boot();
        [$owner, $project] = $this->scenario('pin-new');
        $cardId = Uuid::v7();

        $result = $this->resolve($owner, $project, $cardId, 'sonnet', ['opus', 'sonnet']);

        self::assertSame('sonnet', $result->variant);
        self::assertNull($result->switchedFrom);
        $pin = $this->onlyPin();
        self::assertSame((string) $cardId, (string) $pin->cardId);
        self::assertSame('impl-model', $pin->experiment);
        self::assertSame('sonnet', $pin->variant);
        self::assertSame(self::NOW, $pin->createdAt->format(\DateTimeInterface::ATOM));
        self::assertSame(self::NOW, $pin->updatedAt->format(\DateTimeInterface::ATOM));
    }

    public function test_a_pin_the_rule_still_offers_is_kept_and_refreshed(): void
    {
        $this->boot();
        [$owner, $project] = $this->scenario('pin-keep');
        $cardId = Uuid::v7();
        $this->seedPin($project, $cardId, 'opus');

        $result = $this->resolve($owner, $project, $cardId, 'sonnet', ['opus', 'sonnet']);

        self::assertSame('opus', $result->variant);
        self::assertNull($result->switchedFrom);
        $pin = $this->onlyPin();
        self::assertSame('opus', $pin->variant);
        self::assertSame(self::OLD, $pin->createdAt->format(\DateTimeInterface::ATOM));
        self::assertSame(self::NOW, $pin->updatedAt->format(\DateTimeInterface::ATOM));
    }

    public function test_a_pin_the_rule_no_longer_offers_switches_to_the_candidate(): void
    {
        $this->boot();
        [$owner, $project] = $this->scenario('pin-switch');
        $cardId = Uuid::v7();
        $this->seedPin($project, $cardId, 'haiku');

        $result = $this->resolve($owner, $project, $cardId, 'sonnet', ['opus', 'sonnet']);

        self::assertSame('sonnet', $result->variant);
        self::assertSame('haiku', $result->switchedFrom);
        $pin = $this->onlyPin();
        self::assertSame('sonnet', $pin->variant);
        self::assertSame(self::NOW, $pin->updatedAt->format(\DateTimeInterface::ATOM));
    }

    /** Another request can move the pin after this entity manager loaded it. */
    public function test_the_locked_read_ignores_a_stale_pin_in_memory(): void
    {
        $this->boot();
        [$owner, $project] = $this->scenario('pin-stale');
        $cardId = Uuid::v7();
        $this->seedPin($project, $cardId, 'opus');
        $this->em()->getConnection()->executeStatement("UPDATE bridge_experiment_pins SET variant = 'haiku'");

        $result = $this->resolve($owner, $project, $cardId, 'sonnet', ['opus', 'sonnet']);

        self::assertSame('sonnet', $result->variant);
        self::assertSame('haiku', $result->switchedFrom);
    }

    public function test_each_experiment_and_each_card_has_its_own_pin(): void
    {
        $this->boot();
        [$owner, $project] = $this->scenario('pin-separate');
        $cardId = Uuid::v7();
        $this->seedPin($project, $cardId, 'opus');

        $otherExperiment = $this->resolve($owner, $project, $cardId, 'sonnet', ['opus', 'sonnet'], 'review-model');
        $otherCard = $this->resolve($owner, $project, Uuid::v7(), 'sonnet', ['opus', 'sonnet']);

        self::assertSame('sonnet', $otherExperiment->variant);
        self::assertSame('sonnet', $otherCard->variant);
        self::assertSame(3, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_experiment_pins'));
    }

    public function test_another_owners_project_pins_nothing(): void
    {
        $this->boot();
        [, $project] = $this->scenario('pin-foreign');
        $stranger = $this->user($this->em(), 'pin-foreign-stranger@example.com');

        $result = $this->resolve($stranger, $project, Uuid::v7(), 'sonnet', ['opus', 'sonnet']);

        self::assertNull($result->variant);
        self::assertNull($result->switchedFrom);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_experiment_pins'));
    }

    /** The clock goes in before any service reads it. */
    private function boot(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    /** @return array{User, Project} */
    private function scenario(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');

        return [$owner, $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8))];
    }

    private function seedPin(Project $project, Uuid $cardId, string $variant): void
    {
        $em = $this->em();
        $old = new \DateTimeImmutable(self::OLD);
        $em->persist(new ExperimentPin($project, $cardId, 'impl-model', $variant, $old, $old));
        $em->flush();
    }

    /** @param non-empty-list<string> $variants */
    private function resolve(
        User $owner,
        Project $project,
        Uuid $cardId,
        string $candidate,
        array $variants,
        string $experiment = 'impl-model',
    ): ResolveExperimentPinResult {
        $handler = self::getContainer()->get(ResolveExperimentPinHandler::class);
        self::assertInstanceOf(ResolveExperimentPinHandler::class, $handler);

        return $handler(new ResolveExperimentPinCommand(
            owner: $owner,
            handle: (string) $project->id,
            cardId: $cardId,
            experiment: $experiment,
            candidate: $candidate,
            variants: $variants,
        ));
    }

    private function onlyPin(): ExperimentPin
    {
        $this->em()->clear();
        /** @var list<ExperimentPin> $pins */
        $pins = $this->em()->createQuery('SELECT p FROM '.ExperimentPin::class.' p')->getResult();
        self::assertCount(1, $pins);

        return $pins[0];
    }
}
