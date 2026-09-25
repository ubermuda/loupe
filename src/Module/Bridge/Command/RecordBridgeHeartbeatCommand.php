<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\ValueObject\CliUpdateState;
use Symfony\Component\Uid\Uuid;

/** @phpstan-import-type HookRow from Bridge */
final readonly class RecordBridgeHeartbeatCommand
{
    /**
     * @param list<string>       $projects project ids as the bridge sent them, which may name projects the owner does not hold
     * @param list<HookRow>|null $hooks    null keeps the stored rows, because a bridge that predates hooks sends none
     */
    public function __construct(
        public User $owner,
        public Uuid $bridgeId,
        public array $projects,
        public string $cliVersion,
        public ?CliUpdateState $updateState = null,
        public ?string $updateVersion = null,
        public ?array $hooks = null,
    ) {
    }
}
