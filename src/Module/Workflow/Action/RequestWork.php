<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Service\CardPullRequests;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;

/** A fix request also writes the fix-requested card event that the experiment report counts. */
final readonly class RequestWork implements Action
{
    private const string FIX_KIND = 'fix';

    public function __construct(
        private WorkRequestOpener $opener,
        private CardPullRequests $cardPullRequests,
        private CardEventRepository $cardEvents,
    ) {
    }

    #[\Override]
    public static function type(): ActionType
    {
        return ActionType::Request;
    }

    #[\Override]
    public function run(Rule $rule, Card $card, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        $limit = ActionParams::optionalInt($rule, 'limit');
        if (null !== $limit && $state->fires >= $limit) {
            return ActionOutcome::pause(CardPauseKind::WorkLimit, 'work-limit-reached');
        }

        $kind = ActionParams::string($rule, 'kind');
        $outcome = $this->opener->open($rule, $card, $facts, $kind, ActionParams::optionalString($rule, 'capability'));
        if (self::FIX_KIND === $kind && ActionOutcomeKind::Done === $outcome->kind && !$outcome->alreadyLive) {
            $this->recordFixRequested($card, $facts);
        }

        return $outcome;
    }

    private function recordFixRequested(Card $card, Facts $facts): void
    {
        $detail = ['reason' => $facts->pullRequest?->fixReason() ?? 'unknown'];
        $number = $this->cardPullRequests->primary($this->cardPullRequests->forCard($card))?->number;
        if (null !== $number) {
            $detail['pullRequest'] = $number;
        }
        $this->cardEvents->record($card, CardEventKind::FixRequested, CardReporter::System, null, $detail, $facts->now);
    }
}
