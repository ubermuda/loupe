<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Engine\RuleSubject;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\FactFingerprint;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Reads the facts of every card that has a fingerprinted rule state, and tells which form of the hash each state holds.
 * The legacy hash can go once no state holds it alone.
 */
final readonly class CountLegacyFingerprintsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkflowRuleStateRepository $workflowRuleStates,
        private CardDirectory $cards,
        private TemplateSource $templates,
        private FactsBuilder $factsBuilder,
        private FactFingerprint $fingerprint,
        private RuleSubject $ruleSubject,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CountLegacyFingerprintsCommand $command): LegacyFingerprintCount
    {
        $legacyOnly = $current = $changed = $read = 0;
        foreach ($this->workflowRuleStates->findFingerprintedCardIds() as $cardId) {
            $snapshot = $this->cards->find($cardId);
            if (null === $snapshot) {
                continue;
            }
            try {
                $template = $this->templates->forProject($snapshot->projectId);
            } catch (TemplateMissing) {
                continue;
            }
            ++$read;
            $facts = $this->factsBuilder->build($snapshot, $this->clock->now());
            $rules = array_column(array_map(static fn (Rule $rule): array => [$rule->id, $rule], $template->rules), 1, 0);
            foreach ($this->workflowRuleStates->findForCard($cardId) as $state) {
                $rule = $rules[$state->ruleId] ?? null;
                if (null === $state->fingerprint) {
                    continue;
                }
                if (!$rule instanceof Rule) {
                    ++$changed;
                    continue;
                }
                $bound = $this->ruleSubject->bind($rule, $facts)->facts;
                $keys = $rule->when->reads();
                match (true) {
                    $state->fingerprint === $this->fingerprint->of($bound, $keys) => ++$current,
                    $state->fingerprint === $this->fingerprint->legacyOf($bound, $keys) => ++$legacyOnly,
                    default => ++$changed,
                };
            }
            $this->em->clear();
        }

        return new LegacyFingerprintCount($legacyOnly, $current, $changed, $read);
    }
}
