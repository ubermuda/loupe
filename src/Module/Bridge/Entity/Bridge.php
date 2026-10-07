<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\ValueObject\CliInstallMethod;
use App\Module\Bridge\ValueObject\CliUpdateState;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One CLI bridge as its last heartbeat described it. The row belongs to an
 * account rather than a project, because one bridge follows several projects,
 * and each heartbeat replaces it.
 *
 * The key is the owner and the bridge id together, so two accounts that share
 * one config directory, and so one bridge id, each keep a row of their own.
 *
 * @phpstan-type HookRow array{package: string, ref: string, event: string, lastRunAt: ?string, outcome: string, error: ?string}
 * @phpstan-type WorkerPoolRow array{name: string, size: int, inUse: int, queued: int}
 */
#[ORM\Entity(repositoryClass: BridgeRepository::class)]
#[ORM\Table(name: 'bridges')]
// One owner's bridges hold distinct names. The predicate is written the way
// Postgres stores it, so migrate-diff stays quiet.
#[ORM\UniqueConstraint(name: 'uniq_bridges_owner_name', columns: ['owner_id', 'name'], options: ['where' => '(name IS NOT NULL)'])]
class Bridge
{
    public const int MAX_CLI_VERSION_LENGTH = 100;

    public const int MAX_UPDATE_VERSION_LENGTH = 100;

    public const int MAX_NAME_LENGTH = 40;

    /** Refuses control characters alone, as the CLI does before it sends, because a refused name fails the whole heartbeat. Empty matches, because a blank name clears it. */
    public const string NAME_PATTERN = '/^\P{Cc}*$/uD';

    /** The capability of a bridge that reads commands from the outbox and the heartbeat reply. */
    public const string CAPABILITY_COMMANDS = 'commands';

    public const string CAPABILITY_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';

    /** The capability of a bridge that runs a command run again. */
    public const string CAPABILITY_RERUN_COMMAND = 'rerun-command';

    /** The capability of a bridge that reports the usage of an interactive run from the session transcript. */
    public const string CAPABILITY_SESSION_USAGE = 'session-usage';

    /** The capability of a bridge that claims work requests. */
    public const string CAPABILITY_WORK_REQUESTS = 'work-requests';

    /** The capability of a bridge that runs an interactive session. */
    public const string CAPABILITY_INTERACTIVE = 'interactive';

    /** Null when the last heartbeat carried no update report. */
    #[ORM\Column(name: 'update_state', length: 20, nullable: true, enumType: CliUpdateState::class)]
    public ?CliUpdateState $updateState = null;

    /** The version the update concerns, such as the one a rollback left. */
    #[ORM\Column(name: 'update_version', length: self::MAX_UPDATE_VERSION_LENGTH, nullable: true)]
    public ?string $updateVersion = null;

    /** Null when the last update report named no method, or one this server does not know. */
    #[ORM\Column(name: 'install_method', length: 20, nullable: true, enumType: CliInstallMethod::class)]
    public ?CliInstallMethod $installMethod = null;

    /**
     * The hooks the bridge runs, as its last heartbeat reported them.
     *
     * @var list<HookRow>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $hooks = [];

    /**
     * The worker pools of the bridge and their use, as its last heartbeat
     * reported them. Null until a bridge sends a report.
     *
     * @var list<WorkerPoolRow>|null
     */
    #[ORM\Column(name: 'worker_pools', type: Types::JSON, nullable: true)]
    public ?array $workerPools = null;

    /** The server clock at the heartbeat that carried the worker pool rows. */
    #[ORM\Column(name: 'worker_pools_reported_at', nullable: true)]
    public ?\DateTimeImmutable $workerPoolsReportedAt = null;

    /** Whether a person asked the bridge to start no new work. It stays until a person clears it. */
    #[ORM\Column(name: 'pause_requested', options: ['default' => false])]
    public bool $pauseRequested = false;

    /** When a person last changed the pause request, in either direction. */
    #[ORM\Column(name: 'pause_requested_at', nullable: true)]
    public ?\DateTimeImmutable $pauseRequestedAt = null;

    #[ORM\JoinColumn(name: 'pause_requested_by_id', nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $pauseRequestedBy = null;

    /** Whether the bridge said it is paused, as its last heartbeat reported it. Null until a bridge reports it. */
    #[ORM\Column(name: 'paused_reported', nullable: true)]
    public ?bool $pausedReported = null;

    /**
     * The features the bridge said it supports. Null from a bridge that reports none.
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'capabilities', type: Types::JSON, nullable: true)]
    public ?array $capabilities = null;

    /** The name the bridge holds. Null while it asked for none, or while another bridge of the owner holds the one it asked for. */
    #[ORM\Column(name: 'name', length: self::MAX_NAME_LENGTH, nullable: true)]
    public ?string $name = null;

    /** The name the last heartbeat asked for. */
    #[ORM\Column(name: 'requested_name', length: self::MAX_NAME_LENGTH, nullable: true)]
    public ?string $requestedName = null;

    /** What a page calls the bridge. */
    public string $label {
        get => self::labelFor($this->id, $this->name);
    }

    /**
     * @param list<string> $projects
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        /** The id the bridge generates on its first start, so the server never assigns it. */
        #[ORM\Column(type: UuidType::NAME)]
        #[ORM\Id]
        public readonly Uuid $id,

        /** The ids of the owner's projects the bridge follows. Plain values, so a deleted project leaves no dangling key. */
        #[ORM\Column(type: Types::JSON)]
        public array $projects,

        #[ORM\Column(name: 'cli_version', length: self::MAX_CLI_VERSION_LENGTH)]
        public string $cliVersion,

        /** The server clock at the last heartbeat. */
        #[ORM\Column(name: 'last_seen_at')]
        public \DateTimeImmutable $lastSeenAt,
    ) {
    }

    public function takesCommands(): bool
    {
        return \in_array(self::CAPABILITY_COMMANDS, $this->capabilities ?? [], true);
    }

    public function takesReruns(): bool
    {
        return $this->takesCommands() && \in_array(self::CAPABILITY_RERUN_COMMAND, $this->capabilities ?? [], true);
    }

    public function takesSessionUsage(): bool
    {
        return $this->takesCommands() && \in_array(self::CAPABILITY_SESSION_USAGE, $this->capabilities ?? [], true);
    }

    public function takesWorkRequests(): bool
    {
        return \in_array(self::CAPABILITY_WORK_REQUESTS, $this->capabilities ?? [], true);
    }

    /** Whether the bridge can claim a work request that needs the capability. */
    public function canRun(?string $capability): bool
    {
        return $this->takesWorkRequests() && (null === $capability || \in_array($capability, $this->capabilities ?? [], true));
    }

    public function nameClashes(): bool
    {
        return null !== $this->requestedName && null === $this->name;
    }

    /** The name the bridge holds, or the tail of its id, because the head of a uuid v7 is a timestamp that two bridges can share. */
    public static function labelFor(Uuid|string $id, ?string $name): string
    {
        return $name ?? mb_substr(mb_strtolower((string) $id), -12);
    }
}
