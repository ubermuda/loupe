<?php

declare(strict_types=1);

namespace App\Module\Audit;

/**
 * Whether the operation happened, a policy said no, or it broke.
 *
 * `Failed` was added for an operation an actor asked for that then broke: a
 * mail transport that rejected the message, an external call that timed out.
 * The earlier position was that such a failure has no actor and belongs only
 * in the log. That holds for a background diagnostic, and not for a request a
 * person made and did not get, where silence leaves the trail claiming the
 * request succeeded.
 *
 * A `Failed` record says which operation broke, never why. The reason stays in
 * the log line, or becomes a bounded enum in the context. An exception message
 * must never reach the audit context, because it is unbounded text that can
 * carry personal data and has no erasure path.
 */
enum AuditOutcome: string
{
    case Success = 'success';
    case Refused = 'refused';
    case Failed = 'failed';
}
