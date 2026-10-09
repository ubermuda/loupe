<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Contract\FactProvider;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\LegacyFingerprintGroup;
use App\Module\Workflow\Contract\PullRequestList;
use App\Module\Workflow\Contract\SlotKeys;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use Doctrine\DBAL\Connection;

/** Reads what the engine knows about one card: its slots, and the facts of every provider. It logs nothing. */
final readonly class FactsBuilder
{
    public const string BACKLOG_SLOT = SlotKeys::BACKLOG;

    public const string TERMINAL_SLOT = SlotKeys::TERMINAL;

    private const string SAVEPOINT = 'workflow_fact_provider';

    public function __construct(
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private CardDirectory $cards,
        private ProjectRepository $projects,
        private FactProviders $providers,
        private Connection $connection,
    ) {
    }

    public function build(CardSnapshot $snapshot, \DateTimeImmutable $now): Facts
    {
        $provided = array_map(fn (FactProvider $provider): array => $this->provided($provider, $snapshot), $this->providers->byClass);
        $pullRequests = $provided[PullRequestList::class][0] ?? null;
        $card = $this->cards->findWithParentColumn($snapshot->id) ?? throw new \LogicException('A stored card has an id.');
        $project = $this->projects->find($snapshot->projectId) ?? throw new \LogicException('A stored card has a project.');

        return new Facts(
            now: $now,
            slot: $this->slotOfRef($project, $card->column),
            parentSlot: null === $card->parentColumn ? null : $this->slotOfRef($project, $card->parentColumn),
            pullRequest: $pullRequests instanceof PullRequestList ? $pullRequests->primary : null,
            provided: array_map(static fn (array $result): object => $result[0], $provided),
            fingerprints: array_map(static fn (array $result): mixed => $result[1], array_filter($provided, static fn (array $result): bool => !$result[0] instanceof Unreadable)),
            legacyGroups: array_map(static fn (FactProvider&LegacyFingerprintGroup $provider): string => $provider->legacyGroup(), array_filter($this->providers->byClass, static fn (FactProvider $provider): bool => $provider instanceof LegacyFingerprintGroup)),
        );
    }

    /**
     * The facts of the provider and their fingerprint. A savepoint isolates the queries of the provider, so a database error leaves the transaction of the caller usable.
     *
     * @return array{object, mixed}
     */
    private function provided(FactProvider $provider, CardSnapshot $card): array
    {
        $source = $provider::class;
        $savepoint = $this->connection->isTransactionActive() ? self::SAVEPOINT : null;
        if (null !== $savepoint) {
            $this->connection->createSavepoint($savepoint);
        }
        try {
            $source = $provider->source();
            $class = $provider->factsClass();
            $facts = $provider->isOn() ? $provider->build($card) : new Unreadable(UnreadableKind::Off, $source);
            $fingerprint = null;
            if (!$facts instanceof Unreadable) {
                if (!$facts instanceof $class) {
                    throw new \LogicException(\sprintf('The fact provider %s built a %s, not a %s.', $provider::class, $facts::class, $class));
                }
                $fingerprint = $provider->fingerprint($facts);
                json_encode($fingerprint, \JSON_THROW_ON_ERROR);
            }
            if (null !== $savepoint) {
                $this->connection->releaseSavepoint($savepoint);
            }

            return [$facts, $fingerprint];
        } catch (\Throwable $e) {
            if (null !== $savepoint) {
                $this->connection->rollbackSavepoint($savepoint);
            }

            return [new Unreadable(UnreadableKind::Failed, $source, $e), null];
        }
    }

    public function slotOfRef(Project $project, ColumnRef $column): ?string
    {
        return match (true) {
            $column->backlog => self::BACKLOG_SLOT,
            $column->terminal => self::TERMINAL_SLOT,
            default => $this->workflowSlotLinks->findSlotKeyForColumnId($project, $column->id),
        };
    }
}
