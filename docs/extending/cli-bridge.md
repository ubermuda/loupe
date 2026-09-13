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
`GET /api/projects/{handle}/stream` and `GET /api/projects/{handle}/board/columns`, and no
other endpoint. A project's widget token carries a different scope and the
firewall refuses it here.

A prompt holds validated identifiers and slugs only, and the bridge adds a fixed
line that tells the agent to treat the card as data. An event caused by the
site-review widget starts no worker unless its rule sets `allowUntrusted: true`.

The bridge is a supervisor. `--max-workers` bounds the workers that run at once,
three by default, and events past the bound wait in a first-in first-out queue.
A card is held from the moment its event is accepted until its worker exits, so
the same card never runs twice at once. Stopping the bridge drops whatever is
still queued and logs the count, and each card with its rule.

There is no terminal UI. The bridge writes one JSON object per line to stdout
and to its log file, named by `--log-file`. Each line carries a stable `event`
key, so `jq` selects what you want. The log file is appended, so it is a history
across runs.

Unreleased, like the site-review widget it shares a stream with: there is no
published binary, and it needs a Mercure hub to have anything to subscribe to.
