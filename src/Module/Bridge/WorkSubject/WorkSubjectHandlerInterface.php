<?php

declare(strict_types=1);

namespace App\Module\Bridge\WorkSubject;

use App\Module\Bridge\Entity\WorkRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What a module does when the work on one of its subjects ends. A card has
 * no handler, because the workflow engine reacts to every work request change.
 */
#[AutoconfigureTag(self::TAG)]
interface WorkSubjectHandlerInterface
{
    public const string TAG = 'app.bridge.work_subject_handler';

    public static function subjectType(): string;

    /** Runs after the commit that settled the request as done or refused. */
    public function onSettled(WorkRequest $request): void;

    /** Runs after the commit that expired an open request that no bridge took. */
    public function onExpired(WorkRequest $request): void;
}
