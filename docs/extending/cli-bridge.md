---
title: "Command-line bridge"
description: "A Go binary that types submitted site reviews into a Claude Code session. Preview, unreleased."
---

`cli/` holds a small Go binary that closes the loop: it subscribes to your
site-review stream and types each submitted review straight into a Claude Code
session running in tmux. Build it with `just cli-build` — see
[`cli/README.md`](../../cli/README.md) for the commands and flags.

Unreleased, like the site-review widget it listens to: there is no published
binary, and it needs a Mercure hub to have anything to subscribe to.

