<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\CardColumnLookupInterface;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Security\McpBoundProjectVoter;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the worker runs, the cards and the arguments a worker tool call may act on.
 *
 * A run is looked up by id alone and scoped by McpBoundProjectVoter, so a token
 * bound to one project of an owner cannot reach the runs of another.
 */
final readonly class BridgeSubjectResolver
{
    use ResolvesBoundProject;

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
        private WorkerRunRepository $workerRuns,
        private Security $security,
        private CardColumnLookupInterface $cards,
    ) {
    }

    public function requireProject(): Project
    {
        return $this->requireBoundProject($this->projectResolver);
    }

    /** The person the token acts for, who requests a command. */
    public function requireUser(): User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : throw new \LogicException('An MCP call always authenticates a user.');
    }

    /** @param McpBoundProjectVoter::WORKER_RUN_READ|McpBoundProjectVoter::WORKER_RUN_WRITE $attribute */
    public function requireRun(string $runId, string $attribute): WorkerRun
    {
        return $this->findRun($this->parseRunId($runId), $attribute)
            ?? throw new ToolCallException(self::notFound($runId));
    }

    /**
     * Null for a run that does not exist and for a run of another project alike,
     * so a tool cannot probe what exists outside the token's project.
     *
     * @param McpBoundProjectVoter::WORKER_RUN_READ|McpBoundProjectVoter::WORKER_RUN_WRITE $attribute
     */
    public function findRun(Uuid $runId, string $attribute): ?WorkerRun
    {
        // An unbound token is a setup mistake with its own fix, so it is
        // reported before the scope check turns it into "not found".
        $this->requireBoundProject($this->projectResolver);

        $run = $this->workerRuns->find($runId);

        return null !== $run && $this->security->isGranted($attribute, $run) ? $run : null;
    }

    /**
     * The id of the card that cardId or number names in the bound project. Null
     * for a card that does not exist and for a card of another project alike.
     */
    public function findCardId(?string $cardId, ?int $number): ?Uuid
    {
        if (null !== $cardId && null !== $number) {
            throw new ToolCallException('Pass cardId or number, not both.');
        }
        if (null === $cardId && null === $number) {
            throw new ToolCallException('Pass cardId or number.');
        }
        if (null !== $number && $number < 1) {
            throw new ToolCallException(\sprintf('Card numbers count from 1, so %d is not a card number.', $number));
        }
        $id = null === $cardId ? null : $this->parseCardId($cardId);

        $project = $this->requireBoundProject($this->projectResolver);
        if (!$this->security->isGranted(McpBoundProjectVoter::WORKER_RUN_WRITE, $project)) {
            return null;
        }

        if (null === $id) {
            return $this->cards->cardIdOfNumber($project, $number ?? throw new \LogicException('A call with no cardId has a number.'));
        }

        return null === $this->cards->columnOf($project, $id) ? null : $id;
    }

    public static function notFound(string $runId): string
    {
        return \sprintf('Worker run "%s" not found or not accessible.', $runId);
    }

    public function parseRunId(string $runId): Uuid
    {
        try {
            return Uuid::fromString($runId);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException(\sprintf('"%s" is not a valid run ID. Pass the runId of a row of worker_run_list.', $runId), previous: $e);
        }
    }

    private function parseCardId(string $cardId): Uuid
    {
        try {
            return Uuid::fromString($cardId);
        } catch (\InvalidArgumentException $e) {
            $hint = ctype_digit($cardId) ? ' To read a card by its number, pass number instead.' : '';

            throw new ToolCallException(\sprintf('"%s" is not a valid card ID.%s', $cardId, $hint), previous: $e);
        }
    }

    public function optionalBridgeId(?string $bridgeId): ?Uuid
    {
        if (null === $bridgeId) {
            return null;
        }

        try {
            return Uuid::fromString($bridgeId);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException(\sprintf('"%s" is not a valid bridge ID. Pass the bridgeId of a row of bridge_list.', $bridgeId), previous: $e);
        }
    }

    /**
     * @param array<mixed>|null $states
     *
     * @return list<WorkerRunState>
     */
    public function optionalStates(?array $states): array
    {
        $parsed = [];
        foreach ($states ?? [] as $state) {
            $parsed[] = (\is_string($state) ? WorkerRunState::tryFrom($state) : null)
                ?? throw new ToolCallException(\sprintf('Unknown state "%s". Use one of: %s.', \is_string($state) ? $state : get_debug_type($state), implode(', ', array_map(static fn (WorkerRunState $case): string => $case->value, WorkerRunState::cases()))));
        }

        return $parsed;
    }

    /** An ISO 8601 date, or a date and a time, such as 2026-09-30 or 2026-09-30T14:00:00Z. A value with no offset reads as UTC. */
    public function optionalTime(?string $value, string $argument): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        $refusal = \sprintf('%s: "%s" is not an ISO 8601 date, such as 2026-09-30 or 2026-09-30T14:00:00Z.', $argument, $value);
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/D', $value)) {
            throw new ToolCallException($refusal);
        }

        try {
            $time = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new ToolCallException($refusal, previous: $e);
        }

        // PHP rolls an impossible date or time over, so 2026-02-31 reads as 3 March unless it round-trips.
        $written = substr($value, 0, 10).(\strlen($value) > 10 ? 'T'.substr($value, 11, 5) : '');
        if ($time->format(\strlen($value) > 10 ? 'Y-m-d\TH:i' : 'Y-m-d') !== $written) {
            throw new ToolCallException($refusal);
        }

        return $time->setTimezone(new \DateTimeZone('UTC'));
    }
}
