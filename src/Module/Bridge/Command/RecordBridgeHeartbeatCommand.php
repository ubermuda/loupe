<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\ValueObject\CliInstallMethod;
use App\Module\Bridge\ValueObject\CliUpdateState;
use Symfony\Component\Uid\Uuid;

/**
 * @phpstan-import-type HookRow from Bridge
 * @phpstan-import-type WorkerPoolRow from Bridge
 */
final readonly class RecordBridgeHeartbeatCommand
{
    /**
     * @param list<string>             $projects     project ids as the bridge sent them, which may name projects the owner does not hold
     * @param list<HookRow>|null       $hooks        null keeps the stored rows, because a bridge that predates hooks sends none
     * @param list<WorkerPoolRow>|null $workerPools  null keeps the stored rows, because a bridge that predates worker pools sends none
     * @param bool|null                $paused       null keeps the stored value, because a bridge that predates the pause sends none
     * @param list<string>|null        $capabilities null keeps the stored names, because a bridge that predates capabilities sends none
     * @param string|null              $name         null keeps the stored names, because a bridge that predates names sends none; '' clears both; any other value claims the name
     */
    public function __construct(
        public User $owner,
        public Uuid $bridgeId,
        public array $projects,
        public string $cliVersion,
        public ?CliUpdateState $updateState = null,
        public ?string $updateVersion = null,
        public ?array $hooks = null,
        public ?array $workerPools = null,
        public ?bool $paused = null,
        public ?array $capabilities = null,
        public ?CliInstallMethod $installMethod = null,
        public ?string $name = null,
    ) {
    }
}
