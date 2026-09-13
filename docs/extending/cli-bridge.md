---
title: "Command-line bridge"
description: "A Go binary that runs a Claude Code worker for every board card moved to next. Preview, unreleased."
---

`cli/` holds a small Go binary that closes the loop: it watches your Loupe
board and runs a non-interactive Claude Code worker for every card moved to
`next`. The worker is `claude -p <directive>`. It reads the card through the
MCP, prints its answer and exits. The bridge reports the exit code. Build it
with `just cli-build`. See [`cli/README.md`](../../cli/README.md) for the
commands and flags.

The bridge authenticates with an account-level API token that carries the agent
scope. Mint one at `/account`. It reaches `GET /api/projects`,
`GET /api/projects/{handle}/stream` and `GET /api/projects/{handle}/board/columns`,
and no other endpoint. A project's widget token carries a different scope and
the firewall refuses it here.

The bridge is a supervisor. `--max-workers` bounds the workers that run at once,
three by default, and events past the bound wait in a first-in first-out queue.
A card is held from the moment its event is accepted until its worker exits, so
the same card never runs twice at once. Stopping the bridge drops whatever is
still queued and logs the count and the card numbers.

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
