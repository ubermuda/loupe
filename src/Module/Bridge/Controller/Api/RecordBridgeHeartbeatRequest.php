<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\Bridge;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One heartbeat, as the bridge sends it.
 *
 * @phpstan-import-type HookRow from Bridge
 * @phpstan-import-type WorkerPoolRow from Bridge
 */
final class RecordBridgeHeartbeatRequest
{
    /** Far above the projects one account runs, and small enough to bound the JSON column. */
    public const int MAX_PROJECTS = 500;

    /** Far above the hooks one bridge runs, and small enough to bound the JSON column. */
    public const int MAX_HOOKS = 100;

    /** Far above the pools one bridge runs, and small enough to bound the JSON column. */
    public const int MAX_WORKER_POOLS = 50;

    /** Far above the features one bridge reports, and small enough to bound the JSON column. */
    public const int MAX_CAPABILITIES = 20;

    /**
     * @param list<string>|null                $projects
     * @param list<BridgeHookInput>|null       $hooks        null from a bridge that predates hooks
     * @param list<BridgeWorkerPoolInput>|null $workerPools  null from a bridge that predates worker pools
     * @param bool|null                        $paused       null from a bridge that predates the pause
     * @param list<string>|null                $capabilities null from a bridge that predates capabilities
     * @param string|null                      $name         null from a bridge that predates names; blank clears the name
     */
    public function __construct(
        #[Assert\All([new Assert\NotBlank(), new Assert\Uuid()])]
        #[Assert\Count(max: self::MAX_PROJECTS)]
        #[Assert\NotNull]
        #[Assert\Type('list')]
        public ?array $projects = null,

        #[Assert\Length(max: Bridge::MAX_CLI_VERSION_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $cliVersion = null,

        #[Assert\Valid]
        public ?CliUpdateInput $update = null,

        #[Assert\All([new Assert\Type(BridgeHookInput::class)])]
        #[Assert\Count(max: self::MAX_HOOKS)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $hooks = null,

        #[Assert\All([new Assert\Type(BridgeWorkerPoolInput::class)])]
        #[Assert\Count(max: self::MAX_WORKER_POOLS)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $workerPools = null,
        public ?bool $paused = null,

        #[Assert\All([new Assert\Type('string'), new Assert\Regex(pattern: Bridge::CAPABILITY_PATTERN)])]
        #[Assert\Count(max: self::MAX_CAPABILITIES)]
        #[Assert\Type('list')]
        public ?array $capabilities = null,

        #[Assert\Length(max: Bridge::MAX_NAME_LENGTH, normalizer: 'trim')]
        #[Assert\Regex(pattern: Bridge::NAME_PATTERN, normalizer: 'trim')]
        public ?string $name = null,
    ) {
    }

    /**
     * The capability names once each. Null when the bridge sent no report.
     *
     * @return list<string>|null
     */
    public function capabilities(): ?array
    {
        if (null === $this->capabilities) {
            return null;
        }

        return array_values(array_unique($this->capabilities));
    }

    /**
     * The hook rows as the bridge row stores them, with every time in one
     * format. Null when the bridge sent no report.
     *
     * @return list<HookRow>|null
     */
    public function hooks(): ?array
    {
        if (null === $this->hooks) {
            return null;
        }

        return array_map(static fn (BridgeHookInput $hook): array => [
            'package' => trim($hook->package ?? ''),
            'ref' => trim($hook->ref ?? ''),
            'event' => $hook->event ?? '',
            'lastRunAt' => null === $hook->lastRunAt ? null : new \DateTimeImmutable($hook->lastRunAt)->format(\DateTimeInterface::ATOM),
            'outcome' => $hook->outcome ?? '',
            'error' => '' === trim($hook->error ?? '') ? null : trim($hook->error ?? ''),
        ], array_values($this->hooks));
    }

    /**
     * The worker pool rows as the bridge row stores them. Null when the bridge
     * sent no report.
     *
     * @return list<WorkerPoolRow>|null
     */
    public function workerPools(): ?array
    {
        if (null === $this->workerPools) {
            return null;
        }

        return array_map(static fn (BridgeWorkerPoolInput $pool): array => [
            'name' => $pool->name ?? '',
            'size' => $pool->size ?? 0,
            'inUse' => $pool->inUse ?? 0,
            'queued' => $pool->queued ?? 0,
        ], array_values($this->workerPools));
    }

    /**
     * The project ids in canonical form, once each, so an upper-case copy of an
     * id cannot slip past the ownership filter as a second entry.
     *
     * @return list<string>
     */
    public function projectIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $id): string => Uuid::fromString($id)->toRfc4122(),
            $this->projects ?? [],
        )));
    }

    /** Null when the bridge sent no name. Trimmed, because the constraints read the trimmed value. */
    public function name(): ?string
    {
        return null === $this->name ? null : trim($this->name);
    }

    /** Trimmed, because the length constraint measured the trimmed value. */
    public function cliVersion(): string
    {
        return trim($this->cliVersion ?? '');
    }
}
