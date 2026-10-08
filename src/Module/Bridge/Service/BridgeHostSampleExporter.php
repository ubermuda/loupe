<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\BridgeHostSampleRepository;

final readonly class BridgeHostSampleExporter implements UserDataExporterInterface
{
    public function __construct(
        private BridgeHostSampleRepository $bridgeHostSamples,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'bridge_host_samples.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $ownerId = $user->id ?? throw new \LogicException('An exported user always has an id.');
        foreach ($this->bridgeHostSamples->iterateByOwner($ownerId) as ['bridgeId' => $bridgeId, 'sample' => $sample]) {
            yield [
                'bridgeId' => $bridgeId,
                'sampledAt' => $sample->sampledAt->format(\DateTimeInterface::ATOM),
                'cpuPct' => $sample->cpuPct,
                'memUsed' => $sample->memUsed,
                'memTotal' => $sample->memTotal,
                'swapUsed' => $sample->swapUsed,
                'batteryPct' => $sample->batteryPct,
                'onAc' => $sample->onAc,
            ];
        }
    }
}
