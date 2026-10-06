<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\WorkSubject;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlerInterface;

/** Records each call, for a subject type that no module registers yet. */
final class RecordingWorkSubjectHandler implements WorkSubjectHandlerInterface
{
    public const string TYPE = 'analysis';

    /** @var list<WorkRequest> */
    public array $settled = [];

    /** @var list<WorkRequest> */
    public array $expired = [];

    #[\Override]
    public static function subjectType(): string
    {
        return self::TYPE;
    }

    #[\Override]
    public function onSettled(WorkRequest $request): void
    {
        $this->settled[] = $request;
    }

    #[\Override]
    public function onExpired(WorkRequest $request): void
    {
        $this->expired[] = $request;
    }
}
