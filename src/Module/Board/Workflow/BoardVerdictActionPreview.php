<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Board\Service\VerdictActionPreview;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\RuleActions;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Lists the forge writes of the rules that fire on an unsent verdict. */
#[AsAlias(VerdictActionPreview::class)]
final readonly class BoardVerdictActionPreview implements VerdictActionPreview
{
    public function __construct(
        private RuleActions $ruleActions,
    ) {
    }

    /** Every kind gets the same list, because the rules read the unsent verdict and not its kind. */
    #[\Override]
    public function actionsFor(Project $project, CardVerdictKind $kind): array
    {
        $projectId = $project->id ?? throw new \LogicException('A stored project has an id.');
        $codes = [];
        foreach ($this->ruleActions->onCondition($projectId, VerdictUnsent::key(), ForgeWrite::KEY) as $params) {
            $write = \is_string($params['write'] ?? null) ? ForgeWriteKind::tryFrom($params['write']) : null;
            if (ForgeWriteKind::PostReview === $write) {
                $codes[$write->value] = $write->value;
            }
        }

        return array_values($codes);
    }
}
