<?php

declare(strict_types=1);

namespace App\Module\Audit;

/**
 * Whether the operation happened, moved nothing, was denied, or broke.
 *
 * `Unchanged` covers an operation that was accepted and moved no state: the
 * actor asked for a state the resource already held, and the handler is
 * idempotent. `Refused` stays for a policy that said no. Keeping the two apart
 * is what lets a reader count real denials, because an idempotent repeat is not
 * one and the actor saw the operation succeed.
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
    case Unchanged = 'unchanged';
    case Refused = 'refused';
    case Failed = 'failed';
}
