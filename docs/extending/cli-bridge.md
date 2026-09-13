---
title: "Command-line bridge"
description: "A Go binary that runs a Claude Code worker for each board event a local rule matches. Preview, unreleased."
---

`cli/` holds a small Go binary that closes the loop: it watches your Loupe
board and runs a non-interactive Claude Code worker for each event that a rule
in your rule file matches. The worker is `claude -p <prompt>`. It reads the card
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
subscribes, and it stops on a slug the board does not have. It follows one
project for each process for now.

The bridge authenticates with an account-level API token that carries the agent
scope. Mint one at `/account`. It reaches `GET /api/projects`,
`GET /api/projects/{handle}/stream`, `GET /api/projects/{handle}/board/columns`
and `PUT /api/projects/{handle}/bridges/{bridgeId}/rules`, and no other
endpoint. A project's widget token carries a different scope and
the firewall refuses it here. The handle is a project id or a project slug. A
project name does not resolve. The bridge reads the columns by the slug in
`rules.yaml`, and it reads the stream by the project id that answer returns.

A prompt holds validated identifiers and slugs only, and the bridge adds a fixed
line that tells the agent to treat the card as data. An event caused by the
site-review widget starts no worker unless its rule sets `allowUntrusted: true`.

The bridge is a supervisor. `--max-workers` bounds the workers that run at once,
three by default, and events past the bound wait in a first-in first-out queue.
A card runs one worker at a time. An event for a busy card waits and runs after
that worker exits, and the card waits at most once for each rule, so a burst of
moves becomes one follow-up run. Stopping the bridge drops whatever is still
queued and logs the count, and each card with its rule.

Each rule's `maxChain`, three by default, caps the runs in a row that agents'
events start for one card. That stops two rules from moving a card back and
forth for ever. A move by a person resets the count.

There is no terminal UI. The bridge writes one JSON object per line to stdout
and to its log file, named by `--log-file`. Each line carries a stable `event`
key, so `jq` selects what you want. The log file is appended, so it is a history
across runs.

Unreleased, like the site-review widget it shares a stream with: there is no
published binary, and it needs a Mercure hub to have anything to subscribe to.

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
above. Nothing removes a report except a newer one from the same bridge, or the
deletion of the project. A bridge that stops for good leaves its last report in
place.

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
