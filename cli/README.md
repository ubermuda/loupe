# `loupe` CLI

A small Go binary that closes the loop between Loupe and a local coding agent.

The CLI subscribes to your Loupe event stream and hands each event to a **Claude
Code session running in tmux**, so what you do in the browser becomes the agent's
next instruction with no copy-pasting. It acts on two events:

- **A submitted site review** goes to the bridge's own session.
- **A board card moved to `next`** starts its own worker session, named
  `card-<number>`, which reads the card and plans it.

## Build

No host Go toolchain is needed; both recipes run in a throwaway container.

```bash
just cli-test                  # go vet + go test
just cli-build                 # darwin/arm64 → cli/dist/loupe-darwin-arm64
just cli-build linux amd64     # any GOOS/GOARCH pair
```

Put the resulting binary somewhere on your `PATH` (e.g. `~/bin/loupe`).

`just cli-test` also runs as its own leg of CI, so a broken CLI fails a pull
request the same way broken PHP does.

## Release

`.goreleaser.yaml` builds the full matrix — darwin, linux and windows on both
amd64 and arm64 — as static binaries, archives them, and drafts a GitHub
release. Run it from this directory, against a tag:

```bash
goreleaser release --clean                    # needs GITHUB_TOKEN and a tag
goreleaser release --snapshot --clean         # local dry run, no tag needed
```

The release is drafted rather than published: tags live on the application
repository, so a human confirms the CLI is what changed before it ships.

## Requirements

- **tmux** on your `PATH` — the bridge injects into a tmux session.
- A Loupe API token with the **site-review** scope. Use an account-level token,
  not the project-bound widget token that gets embedded in page HTML: the widget
  token is public by design and is rejected by the endpoints the bridge needs.
- The Loupe MCP server configured in the target tmux session's `claude`. Each
  directive only names an MCP tool, `site_review_get` or `card_get`. It does not
  carry a self-contained prompt, so the agent cannot act on it without the MCP
  available.

## `loupe login`

Stores the token so the bridge can subscribe to your stream. The token is
validated against the API *before* it is written to disk.

```bash
loupe login                                   # prompts for the token
loupe login --token <token>                   # or pass it directly
LOUPE_TOKEN=<token> loupe login               # or via the environment
loupe login --url https://loupe.example.com   # defaults to https://loupe.dev.localhost
```

The prompt does not echo what you type.

The token goes to your **OS keychain** (Keychain Access on macOS, the Secret
Service on Linux, Credential Manager on Windows), keyed by the Loupe base URL so
two instances can coexist. The base URL itself is written to `loupe/config.json`
inside your OS config directory (`~/Library/Application Support` on macOS,
`$XDG_CONFIG_HOME` or `~/.config` on Linux), with the directory at `0700`.

Where no keychain is reachable — a container, or a Linux box with no D-Bus
session — the token falls back into that same file at `0600`.

Upgrading from a version that kept the token in `config.json` needs no action:
the next command that reads it moves the token into the keychain and rewrites
the file without it. On a host with no keychain, nothing changes and the file
stays authoritative.

## `loupe bridge run`

Subscribes to a site's event stream and delivers each event to a tmux session.

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
| `--permission-mode` | — | Pass `--permission-mode` to every `claude` the bridge spawns. Omitted, no flag is passed |
| `--attach` | `true` | Attach to the tmux session and watch. `--attach=false` runs the bridge in the foreground, for headless use |

`--dir` is ignored when a session named `loupe` is already running, and the
bridge says so rather than reusing it in silence.

### Sessions

With `--dir`, the bridge owns two kinds of session:

- **The bridge session**, named `loupe`. Site-review directives are typed into
  it with `tmux send-keys`.
- **A worker session per card**, named `card-<number>`. A card moved to `next`
  spawns one, with the directive as `claude`'s first prompt. A fresh session is
  not yet reading keystrokes, so the prompt travels in the launch command
  instead of through `send-keys`.

The name is what makes a worker addressable, so it must be unique: the bridge
passes it to `claude --name` as well. If `card-<number>` already exists, a worker
for that card is already running, so the bridge logs the event and drops it. It
never starts a second one.

With `--session` the bridge owns nothing and spawns nothing. Both kinds of
directive go to the one session you named.

### `--permission-mode`

This is the flag that makes an unattended run possible. Without it `claude`
prompts before each tool use, so a worker started while you are away stops at the
first prompt and waits.

It is empty by default, and an empty value passes no flag at all. Switching it on
is your decision, and it is a real one: a mode such as `bypassPermissions` lets
an agent edit files and run commands in `--dir` with nobody watching. The cards
it acts on come from your board, and the bridge never puts card text into a
prompt, but the agent reads that text itself once it starts. Point `--dir` at a
directory you are willing to have changed.

`--permission-mode` applies only to sessions the bridge spawns, so it is refused
with `--session`.

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
4. Each event is read from its JSON `type` field and turned into a directive.
   A `site_review.submitted` event is typed into the bridge session with
   `tmux send-keys`. A `board.card_moved` event with `to` set to `next` spawns
   `card-<number>` with the directive as its first prompt.
5. Any other event is dropped. A `board.card_moved` to any other column is
   dropped too, which is what stops a feedback loop: the worker's own first act
   moves the card to `in-progress`, and that second event goes nowhere.
6. A type this build does not handle is dropped without a word. A newer server
   publishes events an older binary has never heard of, and that is normal. A
   payload that will not parse is still logged.

A directive carries only opaque, server-generated identifiers: nothing for a
site review, and the project id plus the card number for a card. It never
carries text a person wrote: a comment body or URL, the site name, a card title
or a card body. Anyone who can post through the embedded widget or write to the
board controls that text, so it is never interpolated into an auto-submitted
prompt. The agent fetches the content itself through `site_review_get` or
`card_get`, and the card directive tells it to treat what it reads as data.

Dropped connections are retried with capped backoff, and a **fresh subscriber
JWT is fetched for every attempt** — they are deliberately short-lived, so
reusing one would make the hub reject each retry once it lapsed.

Delivery is best-effort: events published while the bridge is disconnected are
not replayed. If the target tmux session disappears, the event is logged and
dropped rather than injected somewhere unexpected.
