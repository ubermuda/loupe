---
title: "Worker run API"
description: "The endpoints a command-line bridge reports its worker runs to, how the server infers a run it no longer hears about, and how long the server keeps the record."
---

A [command-line bridge](../extending/cli-bridge.md) runs a Claude Code worker
for each board event one of its rules matches. A worker that finishes reports
itself through a tool call, so the board shows what it did. A worker that
crashes, that is killed, or that never starts writes nothing.

These endpoints are where the bridge reports the run itself. The bridge reports
each state of a run as it happens, from the moment it accepts the event to the
moment the worker ends. The server keeps one row per run and a history of the
states the run reached. A bridge built before run states reports each run once,
when it ends. The project's [Worker runs page](../using/worker-runs.md) shows
what the server holds.

Every endpoint on this page authenticates with an OAuth token that carries the
agent scope. `loupe login` gets one. The firewall refuses a token that carries
the `site-review` or the `mcp` scope.

## The states of a run

| State | Who sets it | Meaning |
|---|---|---|
| `queued` | the bridge | the bridge accepted the event, and the run waits for a worker slot or for its card |
| `replaced` | the bridge | a newer event for the same card and rule took the place of this run in the queue |
| `resumed` | the bridge | the ask the session waited on closed, and the bridge resumes the session |
| `skipped` | the bridge | the session already read every answer of its ask, so the bridge does not resume it |
| `running` | the bridge | the worker process started |
| `waiting-for-person` | the bridge | the rule's `maxChain` cap stopped the run. A move by a person starts a new run |
| `dropped` | the bridge | the bridge stopped, or the rule died, while the run still waited |
| `succeeded` | the bridge | the worker exited with code 0 |
| `failed` | the bridge | the worker exited with any other code |
| `not-started` | the bridge | the worker process never started |
| `timed-out` | the server | the bridge stopped sending its heartbeat while the run was open |
| `lost` | the server | the bridge reconnected, and it no longer holds the run |

`queued`, `resumed` and `running` are open states. Every other state closes the
run. `succeeded`, `failed` and `not-started` are the outcomes, and only an
outcome carries an exit code, a failure reason and an output.

## Reporting a run state

`PUT /api/projects/{handle}/worker-runs/{runId}`

The handle is a project id or a project slug. A project name does not resolve.
`runId` is a uuid the bridge generates for each event it accepts. The server
identifies a run by its project, its `bridgeId` and its `runId`.

```json
{
  "bridgeId": "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90",
  "state": "running",
  "at": "2026-09-13T10:00:00+00:00",
  "cardId": "0199a0e2-b1f3-7a44-9c11-2d3e4f506172",
  "cardNumber": 42,
  "ruleName": "plan",
  "sessionId": "5f0c2b1e-8d4a-4c3b-9e2f-1a0b3c4d5e6f",
  "startedAt": "2026-09-13T10:00:00+00:00"
}
```

Every report carries the card and the rule, so the first report the server
reads can create the run. The reports of one run can therefore arrive in any
order.

| Field | Rule |
|---|---|
| `bridgeId` | required. A uuid the bridge generates once and keeps. It points at no table, so any uuid is accepted |
| `state` | required. One of the ten states the bridge sets. The server refuses `timed-out` and `lost` |
| `at` | required. When the run reached the state, on the bridge clock, as an ISO 8601 timestamp |
| `cardId` | required. The uuid of the card the run is for. It is a plain value, so a deleted card leaves its run history intact |
| `cardNumber` | required. The short number the card shows, counting from 1 inside the project, at most 2147483647 |
| `ruleName` | required. The rule that matched, 1 to 100 characters after trimming |
| `sessionId` | the uuid of the Claude Code session the worker runs as. Required for `running` |
| `startedAt` | when the worker started, on the bridge clock. Required for `running` |
| `endedAt` | when the worker ended, on the bridge clock. Required for an outcome, and it cannot be before `startedAt` |
| `exitCode` | the process exit code, between -255 and 255. `succeeded` needs 0, `failed` needs any other code, and `not-started` needs `null` |
| `failureReason` | why the process never started, at most 1000 characters. Required for `not-started`, and refused with an exit code |
| `output` | what the worker printed, at most 4000 characters. Required for an outcome, and it may be empty |
| `askId` | the ask a `resumed` run continues, at most 100 characters |
| `replacedBy` | the `runId` of the run that replaced a `replaced` run, a uuid |
| `maxChain` | the cap that stopped a `waiting-for-person` run, a positive integer |
| `reason` | why a run was `dropped`: `shutdown` or `rule_dead` |

The server checks the shape of `askId`, `replacedBy`, `maxChain` and `reason`,
and it does not store them.

Send any timestamp in any offset. The server converts each one to UTC and
stores it to the second.

### How a report moves the run

A run moves forward only. The open states rank `queued`, then `resumed`, then
`running`, and every closed state ranks above them. A report moves an open run
when its state ranks higher than the state the run holds. A report never moves
a closed run, with two exceptions:

- A report replaces `timed-out` when its state ranks at least as high as every
  state the bridge reported before. A late `queued` therefore does not reopen a
  run that already ran.
- A report replaces `lost` only when its state closes the run.

The server writes a history row for each state the run did not hold before, at
the time in `at`. It writes the row also when the state does not move the run,
so the history shows every state the bridge reported. A late `running` report
still fills a missing session and start time.

A report is safe to retry. A state the run already holds changes nothing, and
the server answers 200.

| Status | Body | When |
|---|---|---|
| 201 | `{"id":"<uuid>"}` | the state is new for this run, and the server wrote a history row |
| 200 | `{"id":"<uuid>"}` | the run already held this state, and the report changed nothing |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token carries another scope, such as `site-review` |
| 404 | `{"error":"project_not_found"}` | the user has no project with that handle, and another user's project counts as none |
| 404 | | agent push is switched off on the instance, or the server has no such endpoint |
| 422 | `{"error":"invalid_run_id"}` | `runId` is not a uuid |
| 422 | a problem object with a `violations` list | the body is invalid, and each violation names its field in `propertyPath` |
| 429 | | the token went over the rate limit. See [Rate limit](#rate-limit) |

`id` is the server's own id for the run, which the Worker runs page shows as
the attempt ID. It is not the `runId`.

The bridge reads a 404 with no body as a server that has no run states. It
then falls back on the [finished run report](#reporting-a-finished-run).

## Reporting the runs a bridge holds

`PUT /api/bridges/{bridgeId}/runs`

The bridge sends this report each time it connects to the hub. It lists every
open run the bridge holds, across all its projects.

```json
{
  "runs": [
    {
      "runId": "0199a0e3-1a2b-7c3d-8e4f-5a6b7c8d9e0f",
      "projectId": "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7",
      "state": "running"
    }
  ]
}
```

| Field | Rule |
|---|---|
| `runs` | required. A list of at most 1000 runs, which may be empty |
| `runs[].runId` | required. The uuid the bridge generated for the run |
| `runs[].projectId` | required. The uuid of the run's project |
| `runs[].state` | required. `queued`, `resumed` or `running` |

The server compares the list with the runs of that bridge that are open or
`timed-out`, in the projects the token's user owns:

- A run the list does not name becomes `lost`.
- A `timed-out` run the list names takes the state the list gives.
- An open run the list names does not change.
- A run the list names and the server does not hold stays unknown. Its own
  state report creates it.

Each change writes a history row, stamped with the server clock. The report
never touches a run from the finished run report, because that run has no
`runId`.

| Status | Body | When |
|---|---|---|
| 204 | | the report is taken |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token carries another scope, such as `site-review` |
| 404 | | `bridgeId` is not a uuid, or agent push is switched off on the instance |
| 422 | a problem object with a `violations` list | the body is invalid, and each violation names its field in `propertyPath` |
| 429 | | the token went over the rate limit. See [Rate limit](#rate-limit) |

## Timed out and lost

A run stays open until its bridge reports how it ended. A bridge that dies
cannot send that report, so the server closes the run itself.

A scheduled task runs each minute and marks each open run `timed-out` when its
bridge is quiet. A bridge is quiet when its last
[heartbeat](bridge-heartbeat.md) is older than three heartbeat intervals. The
interval is the `bridge.heartbeat_interval_seconds` flag or the default of 60
seconds, whichever is longer. A lowered flag therefore never shortens the wait. A bridge
that sent no heartbeat counts as quiet once the first report of the run is that
old. One pass times out at most 500 runs, and the next pass takes the rest.

`timed-out` is a guess. A report from the bridge replaces it, and so does a
run inventory that names the run. `lost` is a fact: the bridge reconnected, and
it no longer holds the run. Only a report that closes the run replaces `lost`.

`app.bridge.run_timeout_schedule` in `config/services.yaml` is the cron
expression of the task. `app:time-out-worker-runs` runs the same pass by hand.
See [Console commands](commands.md).

## Reporting a finished run

`POST /api/projects/{handle}/worker-runs`

A bridge built before run states reports each run once, when it ends. A new
bridge uses this endpoint only on a server that has no run states. The handle
follows the same rules as above.

```json
{
  "bridgeId": "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90",
  "sessionId": "5f0c2b1e-8d4a-4c3b-9e2f-1a0b3c4d5e6f",
  "cardId": "0199a0e2-b1f3-7a44-9c11-2d3e4f506172",
  "cardNumber": 42,
  "ruleName": "plan",
  "startedAt": "2026-09-13T10:00:00+00:00",
  "endedAt": "2026-09-13T10:00:21+00:00",
  "exitCode": 0,
  "failureReason": null,
  "output": "reading card 42\nwrote a plan"
}
```

| Field | Rule |
|---|---|
| `bridgeId` | a uuid the bridge generates once and keeps. It points at no table, so any uuid is accepted |
| `sessionId` | required. The uuid of the Claude Code session the worker ran as. The bridge generates a new one for each worker and passes it to `claude --session-id` |
| `cardId` | the uuid of the card the worker was started for. It is a plain value, so a deleted card leaves its run history intact |
| `cardNumber` | the short number the card shows, counting from 1 inside the project, at most 2147483647 |
| `ruleName` | the rule that matched, 1 to 100 characters |
| `startedAt` | when the worker started, on the bridge clock, as an ISO 8601 timestamp |
| `endedAt` | when the worker finished, on the bridge clock. It cannot be before `startedAt`, because both come from the same clock |
| `exitCode` | the process exit code, between -255 and 255. Send `null` when the process never started |
| `failureReason` | why the process never started, at most 1000 characters. Required when `exitCode` is `null`, and refused when it is not |
| `output` | what the worker printed, at most 4000 characters. It may be empty |

A report without `sessionId` is refused with a 422 that names the field. A
bridge built before the field existed sends none, so the server stores none of
its runs. Rebuild the bridge from `cli/` to report runs again.

The pairing of `exitCode` and `failureReason` is enforced, because a process
that ran and failed is a different fault from a process that never started. A
report that sends both, or neither, is refused.

The server writes the run with its outcome and a history of two states:
`running` at `startedAt`, then the outcome at `endedAt`. The outcome is
`succeeded` for exit code 0, `failed` for any other code, and `not-started` for
`null`.

The server stamps its own arrival time on the row. Both clocks are kept: the
bridge clock says when the work happened, and the server clock says when the
report landed. The gap between them is how long the report waited in the
bridge's outbound queue.

A report is safe to retry. The server identifies a run by its project, its
`bridgeId`, its `cardId` and its `startedAt`, so a report it already holds
answers with the row it already wrote and changes nothing. Send the same body
again after a timeout or a lost response. A second run of the same card carries
a later `startedAt`, so it is a new row.

Send either timestamp in any offset. The server converts both to UTC before it
stores them, so the same instant written two ways is the same report, and the
list reads the same whichever offset a bridge runs in.

`startedAt` is stored to the second, and a fraction of a second in the value you
send is dropped. Two runs of one card by one bridge that start inside the same
second therefore count as one report, and the second record is lost. A worker
runs for minutes, so this needs a run that ends in milliseconds, which a failure
to start does. The bridge in `cli/` logs `report_folded` when the server answers
200 to a report it is sending for the first time, so the loss is on the record.

| Status | Body | When |
|---|---|---|
| 201 | `{"id":"<uuid>"}` | the run is stored |
| 200 | `{"id":"<uuid>"}` | the server already held this report, and the body changed nothing |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token carries another scope, such as `site-review` |
| 404 | `{"error":"project_not_found"}` | the user has no project with that handle, and another user's project counts as none |
| 404 | | agent push is switched off on the instance |
| 422 | a problem object with a `violations` list | the body is invalid, and each violation names its field in `propertyPath` |
| 429 | | the token went over the rate limit. See [Rate limit](#rate-limit) |

Send `Accept: application/json` to get a 422 body as JSON, on any endpoint of
this page.

## Feature flag and rate limit

The three endpoints need the `agent.push.enabled` feature flag, as
`GET /api/events` does. A bridge reaches a worker only through the event
stream, so an instance with push off can produce no run to report, and each
endpoint answers 404 there.

### Rate limit

The three endpoints share one limit, `agent_worker_runs`, of 60 requests in one
minute for each token. A run sends a few state reports, and a worker runs for
minutes, so one bridge stays far below the limit.

## What a missing record means

A missing record means "unknown", never "the worker did not run". The bridge
holds its outbound queue in memory. A bridge stopped with Ctrl-C or `SIGTERM`
gives each report it still holds one last attempt, in a short window. A bridge
that dies without warning loses what is in flight for good. Read the list of
runs as what the server was told, not as a complete history.

A run whose closing report never arrives stays open until the server closes it.
The timeout task marks it `timed-out`, or the next run inventory of its bridge
marks it `lost`.

The output is whatever the agent printed. It may carry file contents, paths or
anything else the agent chose to say, and anyone who can view the project can
read it.

## Retention

The server keeps a run record for 180 days, counted from when its first report
arrived. An hourly sweep at minute 20 deletes the rest, with their history.

The `bridge.run_retention_days` feature flag sets the window, and you change it
at **`/admin/feature-flags`**. An instance installed before the flag existed
takes the value from `app.bridge.default_run_retention_days` in
`config/services.yaml`, which is 180. A window below 1 day reads as 1 day, so a
typed zero cannot take the whole history.

`app:purge-worker-runs` runs the same sweep by hand. See
[Console commands](commands.md).

The sweep cuts on the server's arrival time rather than on the bridge clock. A
bridge with a wrong clock would otherwise stamp a run outside the window and
lose it on the next sweep.

Deleting a project deletes its run records with it. Deleting an account deletes
the run records of every project it owned. The account's data export holds
each run with its state and its history.
