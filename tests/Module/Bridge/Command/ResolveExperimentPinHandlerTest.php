<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ResolveExperimentPinCommand;
use App\Module\Bridge\Command\ResolveExperimentPinHandler;
use App\Module\Bridge\Command\ResolveExperimentPinResult;
use App\Module\Bridge\Entity\ExperimentDefinition;
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

    public function test_the_weights_are_stored_then_replaced_for_the_experiment(): void
    {
        $this->boot();
        [$owner, $project] = $this->scenario('pin-weights');

        $this->resolve($owner, $project, Uuid::v7(), 'sonnet', ['opus', 'sonnet'], weights: [['name' => 'opus', 'weight' => 1], ['name' => 'sonnet', 'weight' => 3]]);

        $definition = $this->onlyDefinition();
        self::assertSame((string) $project->id, (string) $definition->project->id);
        self::assertSame('impl-model', $definition->experiment);
        self::assertSame([['name' => 'opus', 'weight' => 1], ['name' => 'sonnet', 'weight' => 3]], $definition->weights);
        self::assertSame(self::NOW, $definition->reportedAt->format(\DateTimeInterface::ATOM));

        $later = '2026-09-30T09:00:00+00:00';
        $clock = self::getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify($later);
        $this->resolve($owner, $project, Uuid::v7(), 'haiku', ['haiku', 'opus'], weights: [['name' => 'haiku', 'weight' => 2], ['name' => 'opus', 'weight' => 5]]);

        $definition = $this->onlyDefinition();
        self::assertSame([['name' => 'haiku', 'weight' => 2], ['name' => 'opus', 'weight' => 5]], $definition->weights);
        self::assertSame($later, $definition->reportedAt->format(\DateTimeInterface::ATOM));
    }

    /** A pin with no metrics resets the list, so the page shows the default metrics. */
    public function test_the_metrics_are_stored_then_reset_for_the_experiment(): void
    {
        $this->boot();
        [$owner, $project] = $this->scenario('pin-metrics');
        $weights = [['name' => 'opus', 'weight' => 1], ['name' => 'sonnet', 'weight' => 1]];

        $this->resolve($owner, $project, Uuid::v7(), 'sonnet', ['opus', 'sonnet'], weights: $weights, metrics: ['merge-rate', 'cost']);

        self::assertSame(['merge-rate', 'cost'], $this->onlyDefinition()->metrics);

        $this->resolve($owner, $project, Uuid::v7(), 'sonnet', ['opus', 'sonnet'], weights: $weights);

        self::assertNull($this->onlyDefinition()->metrics);
    }

    public function test_metrics_with_no_weights_store_no_definition(): void
    {
        $this->boot();
        [$owner, $project] = $this->scenario('pin-metrics-no-weights');

        $this->resolve($owner, $project, Uuid::v7(), 'sonnet', ['opus', 'sonnet'], metrics: ['cost']);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_experiment_definitions'));
    }

    public function test_no_weights_store_no_definition(): void
    {
        $this->boot();
        [$owner, $project] = $this->scenario('pin-no-weights');

        $result = $this->resolve($owner, $project, Uuid::v7(), 'sonnet', ['opus', 'sonnet']);

        self::assertSame('sonnet', $result->variant);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_experiment_definitions'));
    }

    public function test_another_owners_project_stores_no_weights(): void
    {
        $this->boot();
        [, $project] = $this->scenario('pin-weights-foreign');
        $stranger = $this->user($this->em(), 'pin-weights-foreign-stranger@example.com');

        $this->resolve($stranger, $project, Uuid::v7(), 'sonnet', ['opus', 'sonnet'], weights: [['name' => 'opus', 'weight' => 1], ['name' => 'sonnet', 'weight' => 1]]);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_experiment_definitions'));
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

    /**
     * @param non-empty-list<string>                      $variants
     * @param list<array{name: string, weight: int}>|null $weights
     * @param list<string>|null                           $metrics
     */
    private function resolve(
        User $owner,
        Project $project,
        Uuid $cardId,
        string $candidate,
        array $variants,
        string $experiment = 'impl-model',
        ?array $weights = null,
        ?array $metrics = null,
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
            weights: $weights,
            metrics: $metrics,
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

    private function onlyDefinition(): ExperimentDefinition
    {
        $this->em()->clear();
        /** @var list<ExperimentDefinition> $definitions */
        $definitions = $this->em()->createQuery('SELECT d FROM '.ExperimentDefinition::class.' d')->getResult();
        self::assertCount(1, $definitions);

        return $definitions[0];
    }
}
