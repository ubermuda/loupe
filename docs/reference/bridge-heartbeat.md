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
  "update": {"state": "rolled-back", "version": "1.1.0"}
}
```

| Field | Rule |
|---|---|
| `projects` | required. A list of at most 500 project ids, which may be empty. The server keeps the ids of projects the token's user owns, and drops every other id |
| `cliVersion` | required. The version of a release build, such as `1.0.0`, or the commit of a development build. 1 to 100 characters after trimming |
| `update` | optional. The state of the bridge's own [update](../extending/cli-bridge.md#updates) |
| `update.state` | required in `update`. One of the states below |
| `update.version` | optional. The release the state is about, at most 100 characters |
| `hooks` | optional. A list of at most 100 rows, one for each event of each [hook package](../extending/bridge-hooks.md) the bridge runs. A missing or `null` value keeps the rows the server holds, and an empty list clears them |

Each row of `hooks` holds these fields:

| Field | Rule |
|---|---|
| `package` | required. The package, as `owner/repo` or `owner/repo/path`, at most 300 characters after trimming |
| `ref` | required. The ref the operator installed, at most 100 characters after trimming |
| `event` | required. `start`, `stop`, `busy` or `idle` |
| `lastRunAt` | optional. The time of the last run, as an RFC 3339 date. It is missing for a hook that has not run since the bridge started |
| `outcome` | required. `ok`, `failed`, `timeout` or `never` |
| `error` | optional. The end of the output of a failed or timed out run, or the error of a hook that could not start, at most 500 characters after trimming |

| `update.state` | Meaning |
|---|---|
| `current` | The running version is inside the range, and no newer release fits |
| `updating` | The bridge hands over to `version` now |
| `rolled-back` | The bridge went back from `version`, and skips it |
| `blocked` | The bridge cannot write the directory of its binary, so it cannot install `version` |
| `off` | `autoUpdate` is `false`, and `version` waits |
| `dev` | A development build, which never updates |

The bridge sends no `update` until its first check has a state. Each heartbeat
replaces the stored state, and a heartbeat with no `update` clears it. The
agents page shows a chip from the stored state: "Up to date", "Updating",
"Rolled back from" and the version, or "Update blocked". It shows "Needs ^1.0"
in place of all of them when `cliVersion` is outside the range, and a
development build always is. `off` and `dev` show no chip.

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
| 200 | `{"cliRange":"^1.0"}` | the heartbeat is accepted |
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

## Deletion and export

Deleting an account deletes the rows of its bridges. The data export holds them
in `bridges.json`, with the stored update state and version.
