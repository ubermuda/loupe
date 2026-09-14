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

The bridge authenticates with an account-level API token that carries the agent
scope, as it does for [worker runs](worker-runs.md). A project's widget token
carries a different scope, and the firewall refuses it here.

`bridgeId` is the uuid the bridge generates on its first start and keeps in
`config.json`. The server takes it as a lower-case uuid of version 1 or 3 to 8.
The path holds no project, because one bridge follows several projects.

```json
{
  "projects": ["0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90"],
  "cliVersion": "b4e39aa7"
}
```

| Field | Rule |
|---|---|
| `projects` | required. A list of at most 500 project ids, which may be empty. The server keeps the ids of projects the token's user owns, and drops every other id |
| `cliVersion` | required. The build the bridge reports with `loupe version`, 1 to 100 characters after trimming |

The server drops a project id it cannot match to one of the user's projects. It
does not refuse the heartbeat. A project deleted while a bridge runs stays in
that bridge's list until it restarts, and a refusal would make a running bridge
look silent. Another user's project and a project that does not exist read the
same.

The row keeps a deleted project's id until the next heartbeat drops it. Deleting
a project does not touch the rows of the bridges that follow it.

The server stamps `lastSeenAt` from its own clock. The bridge sends no time.

| Status | Body | When |
|---|---|---|
| 204 | | the heartbeat is accepted |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token has no agent scope, such as a widget token |
| 404 | | `bridgeId` is not a uuid the server accepts, or agent push is switched off on the instance |
| 422 | a problem object with a `violations` list | the body is invalid, and each violation names its field in `propertyPath` |
| 429 | | more than 60 heartbeats in one minute from one token |

A bridge id that another account already holds also answers 204, and the server
writes nothing. The answer is the same as for an accepted heartbeat, so the
endpoint cannot tell a caller which bridge ids exist. The server records the
refusal in the audit log as `bridge.heartbeat_refused`.

The limit counts per token. Several bridges can share one token, and at the
default interval each one posts once a minute.

## The interval

The `bridge.heartbeat_interval_seconds` feature flag sets how often a bridge
posts, and you change it at **`/admin/feature-flags`**. The default is 60
seconds. An instance installed before the flag existed takes the value from
`app.bridge.heartbeat_interval_seconds` in `config/services.yaml`, which is 60.
A value below 1 reads as the default.

`GET /api/events` shares the value with each bridge in its `flags` map. A bridge
reads the map at start and at each reconnect, so a change reaches a running
bridge at its next reconnect. A lower interval makes the bridges of one token
reach the limit sooner.

## Deletion and export

Deleting an account deletes the rows of its bridges. The data export holds them
in `bridges.json`.
