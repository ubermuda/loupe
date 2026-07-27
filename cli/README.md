# `loupe` CLI

A small Go binary that closes the loop between Loupe and a local coding agent.

When you submit a site review in the browser, the CLI receives it in real time
and types it straight into a **Claude Code session running in tmux** — so the
feedback you just left on a page becomes the agent's next instruction, without
copy-pasting.

## Build

No host Go toolchain is needed; both recipes run in a throwaway container.

```bash
just cli-test                  # go vet + go test
just cli-build                 # darwin/arm64 → cli/dist/loupe-darwin-arm64
just cli-build linux amd64     # any GOOS/GOARCH pair
```

Put the resulting binary somewhere on your `PATH` (e.g. `~/bin/loupe`).

## Requirements

- **tmux** on your `PATH` — the bridge injects into a tmux session.
- A Loupe API token with the **site-review** scope. Use an account-level token,
  not the project-bound widget token that gets embedded in page HTML: the widget
  token is public by design and is rejected by the endpoints the bridge needs.
- The Loupe MCP server configured in the target tmux session's `claude`. The
  injected directive only names the `get_site_review` MCP tool — it does not
  carry a self-contained prompt — so the agent cannot act on it without the
  MCP available.

## `loupe login`

Stores the token so the bridge can subscribe to your stream. The token is
validated against the API *before* it is written to disk.

```bash
loupe login                                   # prompts for the token
loupe login --token <token>                   # or pass it directly
LOUPE_TOKEN=<token> loupe login               # or via the environment
loupe login --url https://loupe.example.com   # defaults to https://loupe.dev.localhost
```

Credentials are written to `loupe/config.json` inside your OS config directory
(`~/Library/Application Support` on macOS, `$XDG_CONFIG_HOME` or `~/.config` on
Linux), with the directory at `0700` and the file at `0600`.

## `loupe bridge run`

Subscribes to a site's review stream and injects each submitted review into a
tmux session.

You must pass exactly one of `--dir` or `--session`:

```bash
# Spawn `claude` in a new tmux session (named "loupe") in a project directory
loupe bridge run --dir ~/Code/my-app

# …or attach to a tmux session you already have running
loupe bridge run --session my-session
```

| Flag | Default | Purpose |
|---|---|---|
| `--dir` | — | Spawn `claude` in a **new** tmux session in this directory |
| `--session` | — | Attach to an **existing** tmux session or target |
| `--site` | interactive | Which site to bridge, by name or id. Omitted, you get a numbered picker (requires a TTY) |
| `--attach` | `true` | Attach to the tmux session and watch. `--attach=false` runs the bridge in the foreground, for headless use |

### Attached vs headless

**Attached** (the default) hands your terminal to `tmux attach`, so you watch
Claude work. Because tmux owns the terminal, the bridge's own logging goes to
`bridge.log` in the config directory instead of stdout — printing would corrupt
the display. Detach with `Ctrl-b d`; that also stops the bridge. While attached,
`Ctrl-C` belongs to Claude, not to the bridge.

**Headless** (`--attach=false`) blocks in the foreground and logs to stdout;
`Ctrl-C` or `SIGTERM` stops it. `--site` is required when there's no TTY.

## How it works

1. `GET /api/site-review/sites` lists your sites (the picker).
2. `GET /api/site-review/stream?site=…` returns the Mercure hub URL, the
   per-site topic, and a short-lived subscriber JWT.
3. The CLI opens a Server-Sent Events connection to the hub. The connection is
   **outbound**, so it works from behind NAT with no inbound port.
4. Each `site_review.submitted` event is turned into a directive and delivered
   with `tmux send-keys`.

The directive carries only opaque, server-generated identifiers — the review
id and a comment count — never reviewer-controlled text such as comment URLs,
comment bodies, or the site name. Anyone who can post through the embedded
widget controls that text, so it is never interpolated into the
auto-submitted prompt; the agent fetches the actual comment content itself
via the `get_site_review` MCP tool, which resolves the site from its own
bound token.

Dropped connections are retried with capped backoff, and a **fresh subscriber
JWT is fetched for every attempt** — they are deliberately short-lived, so
reusing one would make the hub reject each retry once it lapsed.

Delivery is best-effort: events published while the bridge is disconnected are
not replayed. If the tmux session disappears, the review is logged and dropped
rather than injected somewhere unexpected.
