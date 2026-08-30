<?php

declare(strict_types=1);

namespace App\Module\Audit;

/**
 * Whether the operation happened, or a policy said no. `refused` rather than
 * `failure`: an operational failure has no actor whose action is being
 * recorded and does not belong in this trail, and `failure` would invite it in.
 */
enum AuditOutcome: string
{
    case Success = 'success';
    case Refused = 'refused';
}
