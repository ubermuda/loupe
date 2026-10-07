<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Service\CardEventCause;
use App\Module\Board\Service\CardMoveGuard;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

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
    ) {
    }

    #[\Override]
    public function allows(Card $card, BoardColumn $to, CardReporter $actor, ?CardEventCause $cause): bool
    {
        if (!$this->automation->runsFor($card->project)
            || CardReporter::System === $actor
            || $to === $card->column) {
            return true;
        }

        $projectId = $card->project->id ?? throw new \LogicException('A stored project has an id.');
        try {
            $template = $this->templates->forProject($projectId);
        } catch (TemplateMissing) {
            return true;
        }

        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
        if ($this->cardHolds->isHeld($card->project, $cardId)) {
            return true;
        }

        $from = $this->facts->slotOf($card->column);
        $target = $this->facts->slotOf($to);

        $isParentRun = null;
        foreach ($template->manualMoves as $move) {
            if (!self::matches($move->from, $from) || !self::matches($move->to, $target)) {
                continue;
            }
            if (null === $move->by) {
                return true;
            }
            $isParentRun ??= $this->isParentRun($card, $cause);
            if ($isParentRun) {
                return true;
            }
        }

        return false;
    }

    /** An interactive run takes any name, so only an open stored worker run counts. */
    private function isParentRun(Card $card, ?CardEventCause $cause): bool
    {
        $parentId = $card->parent?->id;
        if ('run' !== $cause?->type || null === $parentId) {
            return false;
        }
        $run = $this->workerRuns->findOneByIdAndProjectId((string) ($cause->fields['run'] ?? ''), (string) $card->project->id);
        if (null === $run) {
            return false;
        }
        if (self::isOpenWorkerRunOn($run, $parentId)) {
            return true;
        }

        // The cause prefers a run on the child, so a resumed session can name an older child run.
        $parentRun = null === $run->sessionId ? null : $this->workerRuns->findLikeliestOfSession($card->project, $run->sessionId, $parentId);

        return null !== $parentRun && self::isOpenWorkerRunOn($parentRun, $parentId);
    }

    private static function isOpenWorkerRunOn(WorkerRun $run, Uuid $cardId): bool
    {
        return WorkerRunKind::Worker === $run->kind
            && $run->state->isOpen()
            && true === $run->cardId()?->equals($cardId);
    }

    /** A column no slot links matches the wildcard alone. */
    private static function matches(string $end, ?string $slot): bool
    {
        return self::ANY_COLUMN === $end || (null !== $slot && $end === $slot);
    }
}
