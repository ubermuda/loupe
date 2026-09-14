---
title: "Command-line bridge"
description: "A Go binary that runs a Claude Code worker for each board event a local rule matches. Preview, unreleased."
---

`cli/` holds a small Go binary that closes the loop: it watches your Loupe
board and runs a non-interactive Claude Code worker for each event that a rule
in your rule file matches. The worker is `claude -p --session-id <uuid> -- <prompt>`, with a new session id for each worker. It reads the card
through the MCP, prints its answer and exits. The bridge reports the exit code.
Build it with `just cli-build`. See [`cli/README.md`](../../cli/README.md) for
the commands, the flags and the rule format.

The rules live in `rules.yaml`, beside the CLI's `config.json`. Each rule names
an event type, a project slug, the column a card enters, and the prompt to run.
The `projects` map in the same file gives each project the directory its workers
run in. The bridge refuses to start without the file.

`loupe bridge run` no longer takes `--site` or `--dir`. To upgrade, write a
`rules.yaml` with one project and one rule on `to: next`. `cli/README.md` shows
that file, and the bridge prints it when it finds none.

The bridge checks every project and column slug against the server before it
subscribes, and it stops on a slug the board does not have. Its error then lists
the slugs you own. One bridge follows every project you own on one connection.
It ignores the events of a project `rules.yaml` does not map, and logs that
project once.

A project you create while the bridge runs reaches it with no restart. The
bridge ignores that project until you map it and restart. When a
mapped project is deleted or stops being yours, the bridge logs `project_gone`
once, with the rules that stop working.

The bridge authenticates with an account-level API token that carries the agent
scope. Mint one at `/account`. It reaches `GET /api/projects`, `GET /api/events`,
`GET /api/projects/{handle}/board/columns`,
`POST /api/projects/{handle}/worker-runs`,
`PUT /api/projects/{handle}/bridges/{bridgeId}/rules` and
`PUT /api/bridges/{bridgeId}/heartbeat`, and no other endpoint.
The worker runs endpoint records a finished worker run, and the
[Worker run API](../reference/worker-runs.md) page covers it. The heartbeat
endpoint records that the bridge runs, and the
[Bridge heartbeat API](../reference/bridge-heartbeat.md) page covers it. The
rule health endpoint is below. A project's widget token carries a different scope and the
firewall refuses it here.

The handle is a project id or a project slug. A project name does not resolve.
The bridge reads the columns by the slug in `rules.yaml`.

A prompt holds validated identifiers and slugs only, and the bridge adds a fixed
line that tells the agent to treat the card as data. An event caused by the
site-review widget starts no worker unless its rule sets `allowUntrusted: true`.

The bridge is a supervisor. `--max-workers` bounds the workers that run at once,
three by default, and events past the bound wait in a queue. A card runs one
worker at a time. An event for a busy card waits and runs after that worker
exits, so a later event for another card can start first. The card waits at
most once for each rule, so a burst of moves becomes one follow-up run. Stopping
the bridge drops whatever is still queued and logs the count, and each card with
its rule.

Each rule's `maxChain`, three by default, caps the runs in a row that agents'
events start for one card. That stops two rules from moving a card back and
forth for ever. A move by a person resets the count. An event of a type no rule
names resets nothing, because the bridge drops it unread.

A column rename, a column delete or a project rename can take away a slug a rule
names. The bridge reads `board.column_renamed`, `board.column_deleted` and
`project.renamed` for that reason, and marks each rule on the old slug dead. A
project that a JWT refresh no longer lists kills its rules too. A dead rule
matches nothing until the bridge restarts, and the bridge logs a `rule_dead`
error for each one.

The bridge reports the state of every rule to the rule health endpoint, once for
each mapped project at start and again when a rule dies. The report never
carries a prompt. A failed report is retried with backoff in the background, and
a newer report replaces it. The bridge refuses at start a rule file that the
endpoint would reject, such as a rule name longer than 100 characters. The
bridge names itself by a uuid it keeps in `config.json`.

The bridge reports every run it starts to Loupe. A worker that finishes says so
itself, by writing to the card through an MCP tool. A worker that crashes, that
a signal kills, or that never starts writes nothing at all. The bridge is the
only witness of those, so it posts a record of each run to
`/api/projects/{handle}/worker-runs`. The record names the rule, the card, the
start and end times, the exit code and the output. One bridge follows several
projects, so the handle is the id of the project the event carried.

Loupe records a run against a card. A rule can name an event type that carries
no card number, and the bridge logs `report_skipped` for such a run rather than
sending it. That run has no record, and the log line is the only sign of it.

The queue that carries those reports is held in memory. A failed send waits one
second, then twice as long before each later attempt, up to sixty seconds. The
bridge gives up after ten attempts and logs `report_failed`.

Loupe keys a run by its project, its bridge, its card and the second it started.
Two runs of one card that start inside the same second therefore count as one
report, and the second record is lost. A worker runs for minutes, so this needs
a run that ends in milliseconds, which a failure to start does. The bridge logs
`report_folded` when it happens, so the loss is on the record.

Stopping the bridge kills its workers, and those runs are the ones only the
bridge can report. So it gives each report one last attempt, in a window of five
seconds. It logs `report_dropped` with the count of the reports that miss the
window. A missing record therefore means "unknown", and never "the worker did
not run".

The bridge sends a heartbeat to `/api/bridges/{bridgeId}/heartbeat` once at
start and then at the interval that `bridge.heartbeat_interval_seconds` gives,
60 seconds by default. The heartbeat names the projects the rule file maps and
the build of the bridge. A failed heartbeat is not retried on its own, because
the next interval sends a fresh one. A server with no heartbeat endpoint answers
404, and the bridge logs `heartbeat_unsupported` once and keeps working.

There is no terminal UI. The bridge writes one JSON object per line to stdout
and to its log file, named by `--log-file`. Each line carries a stable `event`
key, so `jq` selects what you want. The log file is appended, so it is a history
across runs.

Unreleased, like the site-review widget it shares a stream with: there is no
published binary, and it needs a Mercure hub to have anything to subscribe to.

## Events endpoint

`GET /api/events` returns what a client needs to follow the events of every
project the token's user owns:

```json
{
  "hubUrl": "https://mercure.example.com/.well-known/mercure",
  "jwt": "<subscriber JWT>",
  "topic": "https://loupe.example.com/users/0192f3a1-0000-7d3e-8f10-a2b3c4d5e6f7/events",
  "projects": [
    {"id": "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7", "slug": "my-app", "name": "My App"}
  ],
  "flags": {"inbox.enabled": false, "bridge.heartbeat_interval_seconds": 60}
}
```

`flags` holds the feature flags a bridge reads. The server lists a flag here
only when its code names the flag, so no other flag reaches a token holder. A
value is a boolean or an integer, as the flag's type says. Today the map holds
two flags:

| Flag | Type | Value |
|---|---|---|
| `inbox.enabled` | boolean | `false` on an instance that holds no row for it |
| `bridge.heartbeat_interval_seconds` | integer | the seconds between two heartbeats, 60 on an instance that holds no row for it. A stored value below 10 reads as 60 |

The bridge reads the map at start and again at each reconnect. A flag change
therefore reaches a running bridge at its next reconnect.

`topic` is the user's own topic. The server publishes each event of a project on
the project's topic and on its owner's topic. The JWT expires after an hour, and
its `subscribe` claim lists the user's topic alone. A user with no project gets
an empty list and the same topic. The endpoint answers `404` when push is
switched off on the instance.

The hub URL and the JWT have the same size however many projects a user owns.
`projects` lists the projects at the moment of the call. A project created later
publishes on the same topic, so a subscriber receives its events with no new
call. Each event names its project in `projectId`.

Events reach the project owner's topic only. A person who is not the owner
receives no event there.

This endpoint replaced `GET /api/projects/{handle}/stream`. A CLI binary built
before the change calls the old route, gets `404`, and must be rebuilt.

## Columns endpoint

`GET /api/projects/{handle}/board/columns` returns the columns of one board, so
the bridge can check its rule file against the board at start. The handle is a
project id or a project slug. A project name does not resolve, and a handle
cannot hold a slash. The token's user must own the project.

```json
{
  "project": { "id": "01a0…", "slug": "my-app" },
  "columns": [
    { "slug": "backlog", "label": "Backlog", "terminal": false, "default": true },
    { "slug": "done", "label": "Done", "terminal": true, "default": false }
  ]
}
```

The columns come in board order. A seeded label is translated, and a label a
person typed comes back as typed. `project.slug` is the project's slug.

| Status | Body | When |
|---|---|---|
| 200 | the object above | the user owns the project |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token has no agent scope, such as a widget token |
| 404 | `{"error":"project_not_found"}` | the user has no project with that handle, and another user's project counts as none |
| 404 | `{"error":"board_disabled"}` | the board is switched off on the instance |
| 429 | | more than 60 reads in one minute from one token |

## Rule health endpoint

`PUT /api/projects/{handle}/bridges/{bridgeId}/rules` stores the health of one
bridge's rules for one project. The board shows a banner to the owner when a
rule is dead, and the column dialogs warn before a rename or a delete breaks a
live rule. The handle follows the same rules as the columns endpoint.
`bridgeId` is a uuid that the bridge generates once and keeps.

The body replaces the whole report of that bridge for that project. A report
with an empty `rules` list clears it. Another bridge's report stays as it is.

```json
{
  "rules": [
    { "name": "plan", "on": "board.card_moved", "columns": ["ready"], "state": "dead", "reason": "column_renamed" },
    { "name": "review", "on": "board.card_moved", "columns": ["review"], "state": "live", "reason": null }
  ]
}
```

| Field | Rule |
|---|---|
| `name` | the rule's name, 1 to 100 characters |
| `on` | an event type such as `board.card_moved`, lower case and dot-separated |
| `columns` | the column slugs the rule watches, at most 50, and it can be empty |
| `state` | `live` or `dead` |
| `reason` | a short machine string such as `column_renamed`, `column_deleted`, `project_renamed` or `unknown_column` when `state` is `dead`, and `null` when it is `live` |

A report holds at most 200 rules. The endpoint stores no prompt text. The
payload has no field for one, and the server drops any key it does not list
above.

A project keeps the 20 newest reports by the time they arrived, and each new
report drops the older ones. Otherwise only a newer report from the same bridge,
or the deletion of the project, removes a report. A bridge that stops for good
leaves its last report in place. To clear it, send an empty report for that
bridge id, `{"rules": []}`. The board banner shows the first eight characters
of the bridge id, and the full id is in the tooltip on those characters.

| Status | Body | When |
|---|---|---|
| 204 | | the report is stored |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token has no agent scope, such as a widget token |
| 404 | `{"error":"project_not_found"}` | the user has no project with that handle, and another user's project counts as none |
| 404 | `{"error":"board_disabled"}` | the board is switched off on the instance |
| 404 | | `bridgeId` is not a uuid |
| 422 | a problem object with a `violations` list | the body is invalid, and each violation names its field in `propertyPath`, such as `rules[0].reason` |
| 429 | | more than 60 reports in one minute from one token |

Send `Accept: application/json` to get the 422 body as JSON.
