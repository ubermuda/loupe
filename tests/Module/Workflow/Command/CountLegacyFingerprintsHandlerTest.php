<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Command;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\CountLegacyFingerprintsCommand;
use App\Module\Workflow\Command\CountLegacyFingerprintsHandler;
use App\Module\Workflow\Engine\RuleSubject;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Service\FactFingerprint;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\TemplateSource;
use App\Tests\Module\Workflow\WorkflowProjects;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CountLegacyFingerprintsHandlerTest extends KernelTestCase
{
    use WorkflowProjects;

    private int $cardNumber = 0;

    public function test_it_counts_each_rule_state_by_the_form_of_the_hash_it_holds(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('count-legacy');
        $this->bindLifecycle($project);
        $rule = $this->firstRule($project);
        $legacy = $this->card($project);
        $current = $this->card($project);
        $changed = $this->card($project);
        $this->state($legacy, $rule, 'legacy');
        $this->state($current, $rule, 'current');
        $this->state($changed, $rule, 'changed');
        $this->state($changed, $rule, 'changed', 'a-rule-that-is-gone');
        $this->em()->flush();

        $count = $this->handler()(new CountLegacyFingerprintsCommand());

        self::assertSame([1, 1, 2, 3], [$count->legacyOnly, $count->current, $count->changed, $count->cards]);
    }

    public function test_a_rule_state_with_no_fingerprint_counts_nowhere(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('count-legacy-none');
        $this->bindLifecycle($project);
        $this->state($this->card($project), $this->firstRule($project), null);
        $this->em()->flush();

        $count = $this->handler()(new CountLegacyFingerprintsCommand());

        self::assertSame([0, 0, 0, 0], [$count->legacyOnly, $count->current, $count->changed, $count->cards]);
    }

    private function state(Card $card, Rule $rule, ?string $form, ?string $ruleId = null): void
    {
        $state = new WorkflowRuleState($card->id ?? throw new \LogicException('A flushed card has an id.'), $card->project, $ruleId ?? $rule->id, new \DateTimeImmutable('2026-10-01 12:00:00'));
        $state->fingerprint = match ($form) {
            null => null,
            'changed' => hash('sha256', 'other facts'),
            default => $this->hashOf($card, $rule, 'legacy' === $form),
        };
        $this->em()->persist($state);
    }

    private function hashOf(Card $card, Rule $rule, bool $legacy): string
    {
        $facts = self::getContainer()->get(FactsBuilder::class)->build($card->snapshot(), self::getContainer()->get(ClockInterface::class)->now());
        $bound = self::getContainer()->get(RuleSubject::class)->bind($rule, $facts)->facts;
        $fingerprint = self::getContainer()->get(FactFingerprint::class);

        return $legacy ? $fingerprint->legacyOf($bound, $rule->when->reads()) : $fingerprint->of($bound, $rule->when->reads());
    }

    private function firstRule(Project $project): Rule
    {
        $templates = self::getContainer()->get(TemplateSource::class);
        self::assertInstanceOf(TemplateSource::class, $templates);

        return $templates->forProject($project->id ?? throw new \LogicException('A flushed project has an id.'))->rules[0];
    }

    private function card(Project $project): Card
    {
        $card = new Card($project, $this->column($project, 'next'), 'Card', '', ++$this->cardNumber, 'feature');
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    private function handler(): CountLegacyFingerprintsHandler
    {
        $handler = self::getContainer()->get(CountLegacyFingerprintsHandler::class);
        self::assertInstanceOf(CountLegacyFingerprintsHandler::class, $handler);

        return $handler;
    }
}
