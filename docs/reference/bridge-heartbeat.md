---
title: "Bridge heartbeat API"
description: "The endpoint a command-line bridge posts to at a fixed interval, so the server knows the bridge is running."
---

A [command-line bridge](../extending/cli-bridge.md) posts a heartbeat at a fixed
interval while it runs. The server keeps one row per bridge, and each heartbeat
replaces that row. The row tells a person when the server last heard from the
bridge.

A heartbeat separates two states that otherwise look the same: no bridge runs,
and a bridge runs but no event reaches it. It does not say why no event reaches
a bridge. A drain that never ticks, for example, leaves a healthy bridge with
nothing to do.

## Posting a heartbeat

`PUT /api/bridges/{bridgeId}/heartbeat`

The bridge authenticates with an OAuth token that carries the agent scope, as
it does for [worker runs](worker-runs.md). The firewall refuses a token that
carries the `site-review` or the `mcp` scope.

`bridgeId` is the uuid the bridge generates on its first start and keeps in
`config.json`. The server takes it as a lower-case uuid of version 1 or 3 to 8.
The path holds no project, because one bridge follows several projects.

```json
{
  "projects": ["0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90"],
  "cliVersion": "1.0.0",
  "update": {"state": "rolled-back", "version": "1.1.0"},
  "workerPools": [
    {"name": "default", "size": 3, "inUse": 2, "queued": 0},
    {"name": "quick", "size": 1, "inUse": 1, "queued": 4}
  ]
}
```

| Field | Rule |
|---|---|
| `projects` | required. A list of at most 500 project ids, which may be empty. The server keeps the ids of projects the token's user owns, and drops every other id |
| `cliVersion` | required. The version of a release build, such as `1.0.0`, or the commit of a development build. 1 to 100 characters after trimming |
| `update` | optional. The state of the bridge's own [update](../extending/cli-bridge.md#updates) |
| `update.state` | required in `update`. One of the states below |
| `update.version` | optional. The release the state is about, at most 100 characters |
| `update.install` | optional. How the CLI was installed. `homebrew` is the only value now. The server stores an unknown value as no value, and does not refuse the heartbeat |
| `hooks` | optional. A list of at most 100 rows, one for each event of each [hook package](../extending/bridge-hooks.md) the bridge runs. A missing or `null` value keeps the rows the server holds, and an empty list clears them |
| `workerPools` | optional. A list of at most 50 rows, one for each worker pool of the bridge. A missing or `null` value keeps the rows the server holds, and an empty list clears them |
| `paused` | optional. `true` when the bridge takes no new work now. A missing or `null` value keeps the state the server holds. See [Pause and commands](#pause-and-commands) |
| `capabilities` | optional. A list of at most 20 names of features the bridge supports. Each name starts with a lower-case letter, and holds 1 to 40 lower-case letters, digits and hyphens. `commands` says that the bridge takes commands. `rerun-command` says that the bridge takes a command of the kind `rerun-command`. `work-requests` says that the bridge claims [work requests](#work-requests), and `interactive` says that it runs an interactive session. A missing or `null` value keeps the list the server holds |
| `workClaims` | optional. A list of at most 200 rows, one for each work request the bridge holds. Each row has an `id` and a `claimToken`, both uuids. The server renews the lease of each claim the bridge still holds, as [Work requests](#work-requests) says. A missing or `null` value renews nothing |

Each row of `hooks` holds these fields:

| Field | Rule |
|---|---|
| `package` | required. The package, as `owner/repo` or `owner/repo/path`, at most 300 characters after trimming |
| `ref` | required. The ref the operator installed, at most 100 characters after trimming |
| `event` | required. `start`, `stop`, `busy` or `idle` |
| `lastRunAt` | optional. The time of the last run, as an RFC 3339 date. It is missing for a hook that has not run since the bridge started |
| `outcome` | required. `ok`, `failed`, `timeout` or `never` |
| `error` | optional. The end of the output of a failed or timed out run, or the error of a hook that could not start, at most 500 characters after trimming |

Each row of `workerPools` holds these fields:

| Field | Rule |
|---|---|
| `name` | required. The pool name. It starts with a lower-case letter, and holds 1 to 40 lower-case letters, digits and hyphens. The pool that a bridge always has is `default` |
| `size` | required. The number of workers that the pool can run at the same time, an integer from 0 to 1000 |
| `inUse` | required. The number of workers that run in the pool now, an integer from 0 to 1000 |
| `queued` | required. The number of runs that wait for a worker of the pool, an integer from 0 to 1000 |

The counts cover every project that the bridge follows. The
[agents page](../using/worker-runs.md#bridge-health) shows them on the card of
the bridge, with the time of the heartbeat that carried them. The server
stamps that time from its own clock when a heartbeat carries a `workerPools`
list, an empty list included. A heartbeat with no list keeps the rows and their
time. A bridge that never sent a `workerPools` list shows no pools.

| `update.state` | Meaning |
|---|---|
| `current` | The running version is inside the range, and no newer release fits |
| `updating` | The bridge hands over to `version` now |
| `rolled-back` | The bridge went back from `version`, and skips it |
| `blocked` | The bridge cannot write the directory of its binary, so it cannot install `version` |
| `off` | `autoUpdate` is not `true`, and `version` waits |
| `dev` | A development build, which never updates |

The bridge sends no `update` until its first check has a state. Each heartbeat
replaces the stored state, and a heartbeat with no `update` clears it. The
agents page shows a chip from the stored state: "Up to date", "Updating",
"Rolled back from" and the version, or "Update blocked". It shows "Needs ^1.0"
in place of all of them when `cliVersion` is outside the range, and a
development build always is. `off` with a `version` shows "Update available"
and the version, with the command that installs it: `brew upgrade loupe` when
`update.install` is `homebrew`, and
`curl -fsSL https://<your Loupe>/install.sh | sh` in all other cases. `off`
with no `version` and `dev` show no chip.

The server drops a project id it cannot match to one of the user's projects. It
does not refuse the heartbeat. A project deleted while a bridge runs stays in
that bridge's list until you remove it from the rule file and reload or restart
the bridge. A refusal would make a running bridge look silent. Another user's
project and a project that does not exist read the same.

The row keeps a deleted project's id until the next heartbeat drops it. Deleting
a project does not touch the rows of the bridges that follow it.

The server stamps `lastSeenAt` from its own clock. The bridge sends no time.

The bridge in `cli/` sends the heartbeat through its outbound queue, the same
queue that carries its worker run reports. The heartbeat has a latest-wins
policy: a newer heartbeat replaces one that has not gone out, and a failed one
is not sent again. The next interval sends a fresh one. A slow or failing
heartbeat never delays a run report.

| Status | Body | When |
|---|---|---|
| 200 | `{"cliRange":"^1.0","paused":false,"commands":[],"workRequests":[],"lostClaims":[]}` | the heartbeat is accepted |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token carries another scope, such as `site-review` |
| 404 | | `bridgeId` is not a uuid the server accepts, or agent push is switched off on the instance |
| 422 | a problem object with a `violations` list | the body is invalid, and each violation names its field in `propertyPath` |
| 429 | | more than 60 heartbeats in one minute from one token. The `Retry-After` header gives the seconds to wait |

The server keys a row by the account and the bridge id together. Two accounts
that share one config directory send the same bridge id, and each account keeps
a row of its own. A heartbeat never changes another account's row.

The first heartbeat of a bridge writes a `bridge.bridge_registered` record to
the audit log. A heartbeat that replaces the row writes none.

`cliRange` is the caret range of CLI versions the server supports. The bridge
installs a release inside it, as
[Updates](../extending/cli-bridge.md#updates) describes. A server built before
the range answers 204 with no body, and the bridge then checks for no update.

The limit counts per token. Several bridges can share one token, and at the
default interval each one posts once a minute.

The reply carries these fields:

| Field | Meaning |
|---|---|
| `cliRange` | the range of CLI versions the server supports |
| `paused` | `true` when the server asks the bridge to take no new work. See [Pause and commands](#pause-and-commands) |
| `commands` | the pending commands of the bridge, oldest first |
| `workRequests` | the open work requests the bridge can claim, oldest first. It is empty for a bridge that does not report `work-requests`. See [Work requests](#work-requests) |
| `lostClaims` | the ids from `workClaims` that the bridge no longer holds. It is empty when the body has no `workClaims` |

## The interval

The `bridge.heartbeat_interval_seconds` feature flag sets how often a bridge
posts, and you change it at **`/admin/feature-flags`**. The default is 60
seconds. An instance installed before the flag existed takes the value from
`app.bridge.default_heartbeat_interval_seconds` in `config/services.yaml`, which is 60.
A value below 10 reads as the default, because a shorter interval lets one
bridge spend most of its token's limit. The bridge applies the same floor to the
value it receives.

`GET /api/events` shares the value with each bridge in its `flags` map. A bridge
reads the map at start and at each reconnect, so a change reaches a running
bridge at its next reconnect. A lower interval makes the bridges of one token
reach the limit sooner.

The project inbox page reads the interval too. It warns on an open ask when its
bridge sent no heartbeat in the last three intervals. See
[When a bridge goes quiet](../using/inbox.md#when-a-bridge-goes-quiet).

## Open runs of a quiet bridge

A bridge that stops sending its heartbeat can no longer report how its runs
end. A task runs each minute and marks each open run of a quiet bridge
`timed-out`. A bridge is quiet when its last heartbeat is older than three
intervals, and a lowered flag never shortens that wait. A later report from the
bridge replaces the timeout.

The bridge also sends the runs it holds to `PUT /api/bridges/{bridgeId}/runs`
each time it connects to the hub. The server marks `lost` each open or timed-out
run of that bridge that the list does not name. See
[Timed out and lost](worker-runs.md#timed-out-and-lost).

## Pause and commands

The server can ask a bridge to take no new work, to stop or resume one worker
run, and to run the command of a failed command run again. The project owner sends these requests from the web UI, as
[Controls in the web UI](worker-runs.md#controls-in-the-web-ui) says.

A pause is a state of the bridge row. `paused` in the heartbeat reply says
whether the server asks the bridge to pause. `paused` in the heartbeat body says
what the bridge does now. A pause never expires, so a bridge that was off
applies it when it comes back.

A stop, a resume or a rerun is a command. The server stores it as pending and sends a
`bridge.command` event on the topic of the project. The heartbeat reply lists
the pending commands of the bridge again in `commands`, so a bridge that missed
the event gets it at its next heartbeat. The bridge ignores a command it already
holds, by `commandId`. Each command carries these fields:

| Field | Meaning |
|---|---|
| `type` | `bridge.command` |
| `projectId` | the project of the run |
| `subject` | `{"type":"bridge-command","id":<commandId>}` |
| `commandId` | the id of the command |
| `kind` | `stop-run`, `resume-run` or `rerun-command` |
| `bridgeId` | the bridge that must act. Another bridge drops the event |
| `runKey`, `sessionId` | the run and its session, or `null` when the run has none |
| `cardId`, `cardNumber`, `ruleName`, `cardColumn` | the card and the rule of the run |
| `resumeIndex` | the place of the run in its resume series, or `null` when the bridge reported none, as for the first run |
| `expiresAt` | the time the command expires, as an RFC 3339 date |

The `bridge.command_ttl_minutes` feature flag sets how long a command waits,
and you change it at **`/admin/feature-flags`**. The default is 15 minutes, from
`app.bridge.default_command_ttl_minutes` in `config/services.yaml`. A value
below 1 reads as the default. A task runs each minute and marks each pending
command past its time `expired`. `app.bridge.command_expiry_schedule` in the
same file sets when that task runs.

A `rerun-command` command names a command run that ended as `failed`,
`timed-out` or `lost`. The bridge runs the command of the run's rule again, as
a new run that continues that run. The server sends a rerun only to a bridge
that reports both the `commands` and the `rerun-command` capabilities. A rerun
holds no card, because the bridge starts a new run.

### Stop timings

A bridge stops a run in three steps. It sends SIGINT to the process group of
the worker. It sends SIGTERM when the worker is still alive after a delay, and
SIGKILL after a second delay. Two feature flags set the delays in milliseconds,
and you change them at **`/admin/feature-flags`**:

| Flag | Default | Meaning |
|---|---|---|
| `bridge.stop_sigterm_after_ms` | 7500 | the wait from SIGINT to SIGTERM |
| `bridge.stop_sigkill_after_ms` | 2500 | the wait from SIGTERM to SIGKILL |

The defaults come from `app.bridge.default_stop_sigterm_after_ms` and
`app.bridge.default_stop_sigkill_after_ms` in `config/services.yaml`. A value
below 100 reads as the default. `GET /api/events` shares both values with each
bridge in its `flags` map. A change reaches a running bridge at its next
reconnect.

### Acknowledging a command

`PUT /api/bridges/{bridgeId}/commands/{commandId}`

The bridge sends its answer after it acts on a command. It uses the same token
as the heartbeat.

```json
{"state": "refused", "reason": "The session is not on this machine."}
```

| Field | Rule |
|---|---|
| `state` | required. `done` or `refused` |
| `reason` | optional. Text of at most 1000 characters |

A pending command takes the state. A command that is already `done`, `refused`,
`expired` or `cancelled` keeps its state, so a second answer changes nothing.
A `done` answer to a resume or a rerun ends the hold of the card, unless a stop
of the card followed the request.

The bridge refuses a rerun with one of these reasons:

| Reason | When |
|---|---|
| The rule of the run no longer runs a command on this bridge. | the rule is gone, or it no longer has `action: command` |
| The card has a run that is still open on this bridge. | a run of the card runs or waits in the queue, a rerun of the same run included |
| The command of the rule needs values that only its first event held: `{to}` | the `run` of the rule names a placeholder other than `{cardId}`, `{cardNumber}`, `{projectId}` and `{project}`, and the reason lists each one |
The server writes a `bridge.command_settled` record to the audit log when an
answer settles a command.

| Status | Body | When |
|---|---|---|
| 200 | `{"commandId":"…","state":"done"}` | the answer is accepted. `state` is the stored state |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token carries another scope |
| 404 | `{"error":"command_not_found"}` | the command does not exist, or belongs to another account or another bridge |
| 404 | | an id is not a uuid, or agent push is switched off on the instance |
| 422 | `{"error":"invalid_state"}`, `{"error":"invalid_reason"}` or `{"error":"reason_too_long"}` | the body is invalid |
| 429 | | the token sent too many reports. The route shares the limit of the [worker run reports](worker-runs.md) |

## Work requests

A work request is a piece of agent work on one card that the server asks a
bridge to run. One bridge claims it, runs it, and sends the result. No part of
the app creates a work request yet. A later release does.

A bridge that claims work requests reports the `work-requests` capability. A
request can also name a capability that the bridge must report, such as
`interactive`. A request with no capability goes to each bridge that reports
`work-requests`.

The `loupe` CLI always reports `commands` and `rerun-command`. It reports
`work-requests` when the `work:` map of its rule file has an entry, and
`interactive` when an entry has `action: interactive`. Each heartbeat sends
`workClaims`, with a row for each claim the CLI holds. A CLI that holds no
claim leaves the key out.
[The work map](../../cli/README.md#the-work-map) says how the CLI runs a
request.

The server offers a request to each bridge that follows the project of the
request and can run it. It sends a `bridge.work_request` event on the topic of
the project. The heartbeat reply lists the open requests again in
`workRequests`, so a bridge that missed the event gets the offer at its next
heartbeat. The reply holds at most 100 requests. The server sends the event
again each time the state of a request changes, so a bridge drops an offer that
another bridge claimed. Each request carries these fields:

| Field | Meaning |
|---|---|
| `type` | `bridge.work_request` |
| `projectId` | the project of the card |
| `subject` | `{"type":"work-request","id":<workRequestId>}` |
| `workRequestId` | the id of the request |
| `kind` | the kind of work, such as `implement` |
| `capability` | the capability a bridge must report to claim the request, or `null` |
| `state` | `open`, `claimed`, `done`, `refused`, `expired` or `cancelled` |
| `cardId`, `cardNumber` | the card of the work |
| `ruleId` | the id of the rule that opened the request |
| `createdAt` | the time the request opened, as an RFC 3339 date |

The event and the reply never carry the claim token.

### Claiming a request

`POST /api/bridges/{bridgeId}/work-requests/{workRequestId}/claim`

The bridge claims an open request before it starts the work. It uses the same
token as the heartbeat, and the request has no body. Of two bridges that claim
one request, one gets the claim and the other gets `already_claimed`.

```json
{
  "workRequestId": "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90",
  "claimToken": "5b0a4c8e-2f61-4d3a-9c7e-8a1b2c3d4e5f",
  "leaseUntil": "2026-10-01T12:32:00+00:00",
  "workRequest": {"type": "bridge.work_request", "state": "claimed"}
}
```

`workRequest` holds every field of the table above. The bridge keeps
`claimToken`, and sends it back with the result and with each heartbeat. The
server writes a `bridge.work_request_claimed` record to the audit log. The
record holds no claim token.

| Status | Body | When |
|---|---|---|
| 200 | the claim | the bridge holds the claim |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token carries another scope |
| 404 | `{"error":"work_request_not_found"}` | the request does not exist, belongs to another account, or belongs to a project the bridge does not follow |
| 404 | | an id is not a uuid, or agent push is switched off on the instance |
| 409 | `{"error":"already_claimed"}` | the request is not open. Another bridge holds it, or its state is `done`, `refused`, `expired` or `cancelled` |
| 422 | `{"error":"unknown_bridge"}` | the server has no heartbeat of this bridge from this account |
| 422 | `{"error":"capability_missing"}` | the bridge does not report `work-requests`, or the capability the request needs |
| 429 | | more than 60 claims in one minute from one token |

### The lease

A claim holds a lease of 2 minutes. `app.bridge.work_request_lease_seconds` in
`config/services.yaml` sets it. Each heartbeat renews the leases of the claims
it names in `workClaims`. The server renews a claim only when this bridge holds
it with that token, and the project of the request belongs to the account of
the heartbeat. The reply names each other id in `lostClaims`. The bridge then
stops the worker of each lost claim, because another bridge can take the work.

A task runs each minute and opens again each claimed request whose lease ran
out. It clears the bridge and the token of the claim, and sends a
`bridge.work_request` event with the state `open`. Another bridge can then claim
the work of a bridge that stopped. `app.bridge.work_request_reopen_schedule` in
the same file sets when that task runs. `app:reopen-lapsed-work-requests` runs
the same sweep once by hand.

The claim stays with its holder after `leaseUntil` passes, until that task
opens the request again. Until then, the holder can still renew or settle the
claim. A bridge must therefore treat a claim as lost only when the reply names
it in `lostClaims`, or when the result answers `claim_lost`.

### Sending the result

`PUT /api/bridges/{bridgeId}/work-requests/{workRequestId}/result`

The bridge sends the result after it ends the work. It uses the same token as
the heartbeat.

```json
{"claimToken": "5b0a4c8e-2f61-4d3a-9c7e-8a1b2c3d4e5f", "state": "refused", "reason": "worktree-dirty"}
```

| Field | Rule |
|---|---|
| `claimToken` | required. The token of the claim, a uuid |
| `state` | required. `done` or `refused` |
| `reason` | optional. A code, not text. It starts with a lower-case letter, and holds 1 to 64 lower-case letters, digits and hyphens. A blank value reads as no reason |

The claim token fences the claim. A bridge whose lease ran out, and whose
request another bridge then claimed, gets `claim_lost`, and the result of the
new holder stands. A second result with the same token and the same state
answers 200 and changes nothing. The first result wins, so the stored reason
stays the reason of the first result.
A result that settles the request sends a `bridge.work_request` event, and
writes a `bridge.work_request_settled` record to the audit log.

| Status | Body | When |
|---|---|---|
| 200 | `{"workRequestId":"…","state":"done"}` | the result is accepted. `state` is the stored state |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token carries another scope |
| 404 | `{"error":"work_request_not_found"}` | the request does not exist, or belongs to another account |
| 404 | | an id is not a uuid, or agent push is switched off on the instance |
| 409 | `{"error":"claim_lost"}` | this bridge does not hold the claim with this token, or the request already settled with another state |
| 422 | `{"error":"invalid_state"}`, `{"error":"invalid_claim_token"}` or `{"error":"invalid_reason"}` | the body is invalid |
| 429 | | more than 60 results in one minute from one token |

## Deletion and export

Deleting an account deletes the rows of its bridges and their commands. The
data export holds the bridges in `bridges.json`, with the stored update state,
version and install method, the hook rows, the worker pool rows with their
report time, the pause state and the capabilities. It holds the commands in
`bridge_commands.json`.

Deleting a project deletes its work requests. The data export holds the work
requests of the projects the account owns in `bridge_work_requests.json`. Each
row has its project, its card, its kind, its capability and its rule. It also
has its state, its bridge, its claim count, its lease, its reason and its times. The claim
token stays out of the export, because a bridge uses it as a credential.
