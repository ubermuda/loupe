<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Contract\ActionOutcomeKind;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\ChildChoices;
use App\Module\Workflow\Contract\ChildChoiceStep;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the `childChoices` of the stored template and runs the actions of a choice on the card, the way the answer of an owner does. */
#[AsAlias(ChildChoices::class)]
final readonly class WorkflowChildChoices implements ChildChoices
{
    public function __construct(
        private WorkflowAutomation $automation,
        private ProjectRepository $projects,
        private TemplateSource $templates,
        private CardDirectory $cards,
        private FactsBuilder $factsBuilder,
        private Actions $actions,
        private ActionContexts $contexts,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function forProject(Uuid $projectId): array
    {
        $steps = [];
        foreach ($this->calls($projectId) as $choice => $calls) {
            $steps[$choice] = array_map(function (ActionCall $call) use ($choice, $projectId): ChildChoiceStep {
                $columns = $this->contexts->columns(self::rule($choice, $call), $projectId);

                return new ChildChoiceStep($call->key, $call->params, $columns['to'] ?? null, $columns['from'] ?? null);
            }, $calls);
        }

        return $steps;
    }

    #[\Override]
    public function run(Uuid $cardId, string $choice): ?string
    {
        $snapshot = $this->cards->refresh($cardId) ?? throw new \LogicException('The card of a child choice exists.');
        $calls = $this->calls($snapshot->projectId)[$choice] ?? null;
        if (null === $calls) {
            return self::NO_CHOICE;
        }
        $now = $this->clock->now();
        foreach ($calls as $call) {
            // Facts again for each action: the one before may have changed the card.
            $facts = $this->factsBuilder->build($snapshot, $now);
            $rule = self::rule($choice, $call);
            $result = $this->actions->get($call->key)->run($this->contexts->for($rule, $snapshot, $facts, 0));
            if (ActionOutcomeKind::Done !== $result->kind) {
                return $result->code ?? $result->kind->value;
            }
        }

        return null;
    }

    private static function rule(string $choice, ActionCall $call): Rule
    {
        return new Rule('child-design-'.$choice, null, new AllOf([]), $call);
    }

    /** @return array<string, list<ActionCall>> */
    private function calls(Uuid $projectId): array
    {
        $project = $this->projects->find($projectId);
        if (null === $project || !$this->automation->runsFor($project)) {
            return [];
        }
        try {
            return $this->templates->forProject($projectId)->childChoices;
        } catch (TemplateMissing) {
            return [];
        }
    }
}
