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

Unreleased, like the site-review widget it shares a stream with: there is no
published binary, and it needs a Mercure hub to have anything to subscribe to.
