<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Workflow;

use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Workflow\DiscoveryFactProvider;
use App\Module\Readiness\Workflow\DiscoveryFacts;
use App\Module\Readiness\Workflow\DiscoveryRequested;
use App\Tests\Module\Readiness\DiscoveryScenario;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DiscoveryFactsTest extends KernelTestCase
{
    use DiscoveryScenario;

    public function test_the_provider_reads_the_latest_run_of_the_card(): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->workflowProject('discovery-facts'));
        $this->discoveryRun($card, DiscoveryRunState::Failed, '2026-10-02 10:00:00');
        $latest = $this->discoveryRun($card, DiscoveryRunState::Requested, '2026-10-02 11:00:00');

        $facts = $this->provider()->build($card->snapshot());

        self::assertEquals(new DiscoveryFacts((string) $latest->id, DiscoveryRunState::Requested), $facts);
        self::assertSame([(string) $latest->id, 'requested'], $this->provider()->fingerprint($facts));
    }

    public function test_a_card_with_no_run_has_empty_facts(): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->workflowProject('discovery-facts-empty'));

        $facts = $this->provider()->build($card->snapshot());

        self::assertEquals(new DiscoveryFacts(null, null), $facts);
        self::assertSame([null, null], $this->provider()->fingerprint($facts));
    }

    public function test_the_provider_is_on_and_names_its_source(): void
    {
        self::bootKernel();

        self::assertTrue($this->provider()->isOn());
        self::assertSame(DiscoveryFacts::class, $this->provider()->factsClass());
        self::assertSame('Readiness', $this->translator()->trans($this->provider()->source()));
    }

    public function test_the_condition_holds_only_while_the_latest_run_is_requested(): void
    {
        $condition = new DiscoveryRequested();
        self::assertSame([DiscoveryFacts::class], $condition->reads([]));

        foreach (DiscoveryRunState::cases() as $state) {
            $facts = FactsMother::facts(provided: [DiscoveryFacts::class => new DiscoveryFacts('run', $state)]);
            self::assertSame(DiscoveryRunState::Requested === $state, $condition->evaluate($facts, []), $state->value);
        }
        self::assertFalse($condition->evaluate(FactsMother::facts(provided: [DiscoveryFacts::class => new DiscoveryFacts(null, null)]), []));
    }

    public function test_the_condition_reads_as_an_english_waiting_sentence(): void
    {
        self::bootKernel();
        $condition = new DiscoveryRequested();

        self::assertSame('Waiting: the card has no requested discovery.', $condition->waitingFor([])->trans($this->translator()));
        self::assertSame('Waiting: the card has a requested discovery.', $condition->waitingFor([], negated: true)->trans($this->translator()));
    }

    private function provider(): DiscoveryFactProvider
    {
        $provider = self::getContainer()->get(DiscoveryFactProvider::class);
        self::assertInstanceOf(DiscoveryFactProvider::class, $provider);

        return $provider;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }
}
