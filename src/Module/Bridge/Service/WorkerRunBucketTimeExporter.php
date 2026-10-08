<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;

final readonly class WorkerRunBucketTimeExporter implements UserDataExporterInterface
{
    public function __construct(
        private WorkerRunBucketTimeRepository $workerRunBucketTimes,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'worker_run_bucket_times.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->workerRunBucketTimes->findByOwner($user) as $time) {
            yield [
                'project' => $time->run->project->name,
                'runKey' => $time->run->runKey?->toRfc4122(),
                'bucket' => $time->bucket,
                'ms' => $time->ms,
            ];
        }
    }
}
