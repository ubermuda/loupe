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
scope. Mint one at `/account`. It reaches `GET /api/projects` and
`GET /api/projects/{handle}/stream`, and no other endpoint. A project's widget token carries
a different scope and the firewall refuses it here. The handle is a project id
or a project slug. A project name does not resolve, so pass `--site` a slug or
an id.

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
