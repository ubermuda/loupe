---
title: "Worker run API"
description: "The endpoint a command-line bridge reports a finished worker run to, and how long the server keeps the record."
---

A [command-line bridge](../extending/cli-bridge.md) runs a Claude Code worker
for each board event one of its rules matches. A worker that finishes reports
itself through a tool call, so the board shows what it did. A worker that
crashes, that is killed, or that never starts writes nothing.

This endpoint is where the bridge reports the run itself. The server keeps one
row per run, and it never changes the row afterwards.

The bridge in `cli/` does not call this endpoint yet, so for now a record
arrives only from a client that sends one.

## Reporting a run

`POST /api/projects/{handle}/worker-runs`

The bridge authenticates with an account-level API token that carries the agent
scope. Mint one at `/account`. A project's widget token carries a different
scope and the firewall refuses it here. The handle is a project id or a project
slug. A project name does not resolve.

```json
{
  "bridgeId": "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90",
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
| `cardId` | the uuid of the card the worker was started for. It is a plain value, so a deleted card leaves its run history intact |
| `cardNumber` | the short number the card shows, counting from 1 inside the project |
| `ruleName` | the rule that matched, 1 to 100 characters |
| `startedAt` | when the worker started, on the bridge clock, as an ISO 8601 timestamp |
| `endedAt` | when the worker finished, on the bridge clock. It cannot be before `startedAt`, because both come from the same clock |
| `exitCode` | the process exit code, between -255 and 255. Send `null` when the process never started |
| `failureReason` | why the process never started, at most 1000 characters. Required when `exitCode` is `null`, and refused when it is not |
| `output` | what the worker printed, at most 4000 characters. It may be empty |

The pairing of `exitCode` and `failureReason` is enforced, because a process
that ran and failed is a different fault from a process that never started. A
report that sends both, or neither, is refused.

The server stamps its own arrival time on the row. Both clocks are kept: the
bridge clock says when the work happened, and the server clock says when the
report landed. The gap between them is how long the report waited in the
bridge's retry queue.

A report is safe to retry. The server identifies a run by its project, its
`bridgeId`, its `cardId` and its `startedAt`, so a report it already holds
answers with the row it already wrote and changes nothing. Send the same body
again after a timeout or a lost response. A second run of the same card carries
a later `startedAt`, so it is a new row.

`startedAt` is stored to the second, and a fraction of a second in the value you
send is dropped. Two runs of one card by one bridge that start inside the same
second therefore count as one report. A worker runs for minutes, so this needs
no attention from a bridge.

| Status | Body | When |
|---|---|---|
| 201 | `{"id":"<uuid>"}` | the run is stored |
| 200 | `{"id":"<uuid>"}` | the server already held this report, and the body changed nothing |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token has no agent scope, such as a widget token |
| 404 | `{"error":"project_not_found"}` | the user has no project with that handle, and another user's project counts as none |
| 404 | | agent push is switched off on the instance |
| 422 | a problem object with a `violations` list | the body is invalid, and each violation names its field in `propertyPath` |
| 429 | | more than 60 reports in one minute from one token |

Send `Accept: application/json` to get the 422 body as JSON.

The endpoint needs the `agent.push.enabled` feature flag. A bridge reaches a
worker only through the push stream, so an instance with push off can produce no
run to report, and the endpoint answers 404 there.

## What a missing record means

A missing record means "unknown", never "the worker did not run". The bridge
holds its retry queue in memory, so a bridge killed between a worker finishing
and its report landing loses that outcome for good. Read the list of runs as
what the server was told, not as a complete history.

The output is whatever the agent printed. It may carry file contents, paths or
anything else the agent chose to say, and anyone who can view the project can
read it.

## Retention

The server keeps a run record for 180 days, counted from when the report
arrived. An hourly sweep at minute 20 deletes the rest.

The `bridge.run_retention_days` feature flag sets the window, and you change it
at **`/admin/feature-flags`**. An instance installed before the flag existed
takes the value from `app.bridge.run_retention_days` in
`config/services.yaml`, which is 180. A window below 1 day reads as 1 day, so a
typed zero cannot take the whole history.

`app:purge-worker-runs` runs the same sweep by hand. See
[Console commands](commands.md).

The sweep cuts on the server's arrival time rather than on the bridge clock. A
bridge with a wrong clock would otherwise stamp a run outside the window and
lose it on the next sweep.

Deleting a project deletes its run records with it. Deleting an account deletes
the run records of every project it owned.
