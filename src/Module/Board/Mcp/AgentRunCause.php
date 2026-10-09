<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Workflow\Contract\CardEventCause;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/** The worker run behind an agent's MCP call, read from the session header that the `loupe` CLI sends. */
final readonly class AgentRunCause
{
    public const string SESSION_HEADER = 'X-Loupe-Session';

    public function __construct(
        private RequestStack $requests,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    /** Null when the call names no session, or a session with no run in the project of the card. */
    public function forCard(Card $card): ?CardEventCause
    {
        $session = $this->requests->getCurrentRequest()?->headers->get(self::SESSION_HEADER);
        if (null === $session || !Uuid::isValid($session)) {
            return null;
        }

        $cardId = $card->id ?? throw new \LogicException('A persisted card has an id.');
        $run = $this->workerRuns->findLikeliestOfSession($card->project, Uuid::fromString($session), $cardId);
        if (null === $run) {
            return null;
        }

        return CardEventCause::run($run->id ?? throw new \LogicException('A persisted run has an id.'), $run->workKind);
    }
}
