<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use App\Module\Workflow\Contract\CardMoveGuard;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** A card is managed while the engine runs for its project, its project has a template, and nobody holds it. */
#[AsAlias(CardMoveGuard::class)]
final readonly class WorkflowCardMoveGuard implements CardMoveGuard
{
    private const string ANY_COLUMN = '*';

    public function __construct(
        private WorkflowAutomation $automation,
        private CardHolds $cardHolds,
        private TemplateSource $templates,
        private FactsBuilder $facts,
        private WorkerRunRepository $workerRuns,
        private ProjectRepository $projects,
    ) {
    }

    #[\Override]
    public function allows(CardSnapshot $card, ColumnRef $to, Actor $actor, ?CardEventCause $cause): bool
    {
        $project = $this->projects->find($card->projectId) ?? throw new \LogicException('A stored card has a project.');
        if (!$this->automation->runsFor($project)
            || Actor::System === $actor
            || $to->id->equals($card->column->id)) {
            return true;
        }

        try {
            $template = $this->templates->forProject($card->projectId);
        } catch (TemplateMissing) {
            return true;
        }

        if ($this->cardHolds->isHeld($project, $card->id)) {
            return true;
        }

        $from = $this->facts->slotOfRef($project, $card->column);
        $target = $this->facts->slotOfRef($project, $to);

        $isParentRun = null;
        foreach ($template->manualMoves as $move) {
            if (!self::matches($move->from, $from) || !self::matches($move->to, $target)) {
                continue;
            }
            if (null === $move->by) {
                return true;
            }
            $isParentRun ??= $this->isParentRun($project, $card, $cause);
            if ($isParentRun) {
                return true;
            }
        }

        return false;
    }

    /** An interactive run takes any name, so only an open stored worker run counts. */
    private function isParentRun(Project $project, CardSnapshot $card, ?CardEventCause $cause): bool
    {
        $parentId = $card->parentId;
        if ('run' !== $cause?->type || null === $parentId) {
            return false;
        }
        $run = $this->workerRuns->findOneByIdAndProjectId((string) ($cause->fields['run'] ?? ''), (string) $card->projectId);
        if (null === $run) {
            return false;
        }
        if (WorkerRunKind::Worker === $run->kind && $run->state->isOpen() && true === $run->cardId()?->equals($parentId)) {
            return true;
        }

        // The cause prefers a run on the child, so a resumed session can name an older child run.
        return null !== $run->sessionId
            && $this->workerRuns->hasOpenWorkerOfSessionOnCard($project, $run->sessionId, $parentId);
    }

    /** A column no slot links matches the wildcard alone. */
    private static function matches(string $end, ?string $slot): bool
    {
        return self::ANY_COLUMN === $end || (null !== $slot && $end === $slot);
    }
}
