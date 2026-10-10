<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** AgentReview asks this port which finding severities fail a review, and the workflow template of the project names them. */
interface AgentReviewFailingSeverities
{
    /** @return list<string> */
    public function of(Uuid $projectId): array;
}
