<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\VerdictActionPreview;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\ForgeWriteKind;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Lists the forge writes of the rules that fire on an unsent verdict, while their opt-in is on. */
#[AsAlias(VerdictActionPreview::class)]
final readonly class WorkflowVerdictActionPreview implements VerdictActionPreview
{
    private const string VERDICT_CONDITION = 'site_review.verdict_unsent';

    public function __construct(
        private WorkflowAutomation $automation,
        private TemplateSource $templates,
        private BoardAutomation $boardAutomation,
    ) {
    }

    /** Every kind gets the same list, because the rules read the unsent verdict and not its kind. */
    #[\Override]
    public function actionsFor(Project $project, CardVerdictKind $kind): array
    {
        if (!$this->automation->runsFor($project)) {
            return [];
        }

        try {
            $template = $this->templates->forProject($project->id ?? throw new \LogicException('A stored project has an id.'));
        } catch (TemplateMissing) {
            return [];
        }

        $settings = $this->boardAutomation->settingsOf($project);
        $codes = [];
        foreach ($template->rules as $rule) {
            $write = self::writeOf($rule);
            if (null !== $write && self::readsVerdict($rule->when) && self::optedIn($write, $settings)) {
                $codes[$write->value] = $write->value;
            }
        }

        return array_values($codes);
    }

    private static function writeOf(Rule $rule): ?ForgeWriteKind
    {
        if (ActionType::ForgeWrite !== $rule->then->type) {
            return null;
        }
        $write = $rule->then->params['write'] ?? null;

        return \is_string($write) ? ForgeWriteKind::tryFrom($write) : null;
    }

    /** A condition under `not` or `any` does not make the rule fire on the verdict, so only the top level counts. */
    private static function readsVerdict(Expression $when): bool
    {
        $leaves = $when instanceof AllOf ? $when->children : [$when];

        return array_any($leaves, static fn (Expression $leaf): bool => $leaf instanceof ConditionLeaf && self::VERDICT_CONDITION === $leaf->condition::key());
    }

    private static function optedIn(ForgeWriteKind $write, BoardAutomationSettings $settings): bool
    {
        return ForgeWriteKind::PostReview === $write && $settings->postWidgetReviews;
    }
}
