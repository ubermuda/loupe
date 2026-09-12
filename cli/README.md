# `loupe` CLI

A small Go binary that closes the loop between Loupe and a local coding agent.

The CLI watches your Loupe board and runs a **non-interactive Claude Code
worker** for every card that moves to `next`, so a card you prioritise in the
browser becomes an agent run with no copy-pasting. A worker is
`claude -p <directive>`. It prints its answer and exits, and the bridge reports
the exit code.

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

- **`claude`** on your `PATH`. The bridge refuses to start without it.
- A Loupe API token with the **site-review** scope. Use an account-level token,
  not the project-bound widget token that gets embedded in page HTML: the widget
  token is public by design and is rejected by the endpoints the bridge needs.
- The Loupe MCP server configured for `claude` in the `--dir` directory. A
  directive only names an MCP tool, `card_get`. It does not carry a
  self-contained prompt, so the agent cannot act on it without the MCP
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

Subscribes to a site's event stream and runs a worker for each card that enters
`next`.

```bash
loupe bridge run --dir ~/Code/my-app
```

| Flag | Default | Purpose |
|---|---|---|
| `--dir` | — | **Required.** Every worker runs in this directory |
| `--site` | interactive | Which site to bridge, by name or id. Omitted, you get a numbered picker (requires a TTY) |
| `--permission-mode` | — | Pass `--permission-mode` to every `claude` the bridge starts. Omitted, no flag is passed |

The command blocks in the foreground and logs to stdout. `Ctrl-C` or `SIGTERM`
stops it, and that also stops every worker in flight. `--site` is required when
there is no TTY.

### Workers

A card that enters `next` starts one worker. The bridge runs
`claude -p <directive>` in `--dir`, with `--permission-mode` in front when you
passed it. The prompt is an argv element, so no shell reads it.

Each worker runs in its own goroutine, so a long run never blocks the event
stream and two cards run at the same time. The bridge logs a line when a worker
starts and a line when it ends, carrying the exit code. A non-zero exit also
carries the worker's captured output.

The bridge reacts to the transition, not to the column. A card dragged to a new
rank inside `next` submits a move with `next` on both sides, and prioritising
that column is an ordinary thing to do, so reacting to the target alone would
start a worker for every card reordered.

One card gets one worker at a time. The bridge holds a key per running worker,
`card-<number>-<project>`, where the project part is the last 12 hex digits of
its id. Card numbers count from 1 inside a project and repeat across them, so
the number alone would let one project's card 87 block another's. A second event
for a card whose worker still runs is logged and dropped. The key is released
when the process exits, so the same card starts a new worker the next time
somebody moves it into `next`.

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

## `loupe version`

Prints the commit the binary was built from, plus the Go version and the
platform. `loupe --version` prints the same two lines.

```
loupe 0f4a2c9b1d7e3f5a6b8c9d0e1f2a3b4c5d6e7f80
go1.26.0 darwin/arm64
```

The commit arrives through `-ldflags`, because the build container mounts `cli/`
alone and has no `.git` to read. `just cli-build` and goreleaser both inject it.
A binary built outside a repository says `loupe unknown`.

`(dirty)` after the commit means the working tree held uncommitted changes at
build time, so the binary matches no commit.

## How it works

1. `GET /api/site-review/sites` lists your sites (the picker).
2. `GET /api/site-review/stream?site=…` returns the Mercure hub URL, the
   per-site topic, and a short-lived subscriber JWT.
3. The CLI opens a Server-Sent Events connection to the hub. The connection is
   **outbound**, so it works from behind NAT with no inbound port.
4. Each event is read from its JSON `type` field. A `board.card_moved` event
   with `toStatus` set to `next` becomes a directive, and the bridge starts
   `claude -p` with it.
5. Any other event is dropped. A `board.card_moved` to any other column is
   dropped too, which is what stops a feedback loop: the worker's own first act
   moves the card to `in-progress`, and that second event goes nowhere.
6. A type this build does not handle is dropped without a word. A newer server
   publishes events an older binary has never heard of, and that is normal. A
   payload that will not parse is still logged.

A directive carries only opaque, server-generated identifiers: the project id,
the card id and the card number. It never carries text a person wrote, such as a
card title or a card body. Anyone who can write to the board controls that text,
so it never reaches an auto-submitted prompt. The agent fetches the content
itself through `card_get`, and the directive tells it to treat what it reads as
data.

Dropped connections are retried with capped backoff, and a **fresh subscriber
JWT is fetched for every attempt** — they are deliberately short-lived, so
reusing one would make the hub reject each retry once it lapsed.

Delivery is best-effort: events published while the bridge is disconnected are
not replayed.
