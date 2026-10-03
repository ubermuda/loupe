<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Service\CardEventCause;
use App\Module\Board\Service\CardMoveGuard;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Workflow\Template\ManualMove;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** A card is managed while the engine runs for its project, its project has a template, and nobody holds it. */
#[AsAlias(CardMoveGuard::class)]
final readonly class WorkflowCardMoveGuard implements CardMoveGuard
{
    private const string BREAKDOWN_KIND = 'breakdown';

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
            || $to === $card->column
            || $this->isBreakdownRun($card, $cause)) {
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

        return array_any(
            $template->manualMoves,
            static fn (ManualMove $move): bool => self::matches($move->from, $from) && self::matches($move->to, $target),
        );
    }

    /**
     * An interactive run takes any name, so only a stored worker run counts.
     * A breakdown runs on the epic and moves its children, so the run's card is the moved card's parent.
     */
    private function isBreakdownRun(Card $card, ?CardEventCause $cause): bool
    {
        if ('run' !== $cause?->type || self::BREAKDOWN_KIND !== ($cause->fields['kind'] ?? null) || null === $card->parent?->id) {
            return false;
        }
        $run = $this->workerRuns->findOneByIdAndProjectId((string) ($cause->fields['run'] ?? ''), (string) $card->project->id);

        return WorkerRunKind::Worker === $run?->kind
            && self::BREAKDOWN_KIND === $run->workKind
            && $card->parent->id->equals($run->cardId);
    }

    /** A column no slot links matches the wildcard alone. */
    private static function matches(string $end, ?string $slot): bool
    {
        return self::ANY_COLUMN === $end || (null !== $slot && $end === $slot);
    }
}
