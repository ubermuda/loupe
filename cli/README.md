# `loupe` CLI

A small Go binary that closes the loop between Loupe and a local coding agent.

The CLI watches your Loupe board and runs a **non-interactive Claude Code
worker** for every card that moves to `next`, so a card you prioritise in the
browser becomes an agent run with no copy-pasting. A worker is
`claude -p <directive>`. It prints its answer and exits, and the bridge reports
the exit code.

The bridge runs three workers at once by default and queues the rest. It writes
one JSON object per line, to stdout and to a log file.

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
| `--permission-mode` | — | Pass `--permission-mode` to every `claude` the bridge starts. Omitted, no flag is passed and a worker can approve nothing |
| `--max-workers` | `3` | Run at most this many workers at once. Later events wait in a queue. Below 1 is a startup error |
| `--log-file` | `bridge.log` in your config dir | Append the JSON log to this path |

The command blocks in the foreground and writes JSON lines to stdout and to the
log file. `Ctrl-C` or `SIGTERM` stops it, and that also stops every worker in
flight. `--site` is required when there is no TTY.

### Workers

A card that enters `next` starts one worker. The bridge runs
`claude -p <directive>` in `--dir`, with `--permission-mode` in front when you
passed it. The prompt is an argv element, so no shell reads it.

Each worker runs in its own goroutine, so a long run never blocks the event
stream and several cards run at the same time. The bridge logs a line when a
worker starts and a line when it ends, carrying the exit code and how long it
took. It owns the worker's streams, so it reports what the worker said as well,
on a clean exit and on a failure alike. Output past 4 KB is dropped and the
report says so.

The bridge reacts to the transition, not to the column. A card dragged to a new
rank inside `next` submits a move with `next` on both sides, and prioritising
that column is an ordinary thing to do, so reacting to the target alone would
start a worker for every card reordered.

One bridge gives a card one worker at a time. It holds a key per accepted card,
`card-<number>-<project>`, where the project part is the last 12 hex digits of
its id. Card numbers count from 1 inside a project and repeat across them, so
the number alone would let one project's card 87 block another's. The key is
claimed when the event is accepted and released when that card's worker exits,
so a card that waits in the queue is as firmly held as one that runs. A second
event for a held card is logged and dropped. The same card starts a new worker
the next time somebody moves it into `next`.

The key lives in the bridge process. Two bridges following one site each keep
their own, so they can both start a worker for the same card. Run one bridge
per site.

### The queue

`--max-workers` bounds the processes, not the pending work. An event that
arrives while every slot is busy waits in an in-memory queue, and the queue has
no length limit. The bridge starts queued cards in arrival order as slots free.

One worker at a time is a surprise for a queue you fill by dragging several
cards, and no bound at all is a way to start twenty agents by accident. Three is
the middle.

Stopping the bridge drops whatever is still queued, because those workers never
started. The bridge logs one `queue_dropped` line naming the count and the
cards, so no trigger disappears in silence. Move those cards into `next` again
to run them.

### Output

The bridge writes one JSON object per line, to stdout and to `--log-file` alike.
A human running it in a terminal therefore reads JSON. That is deliberate: the
bridge is a supervisor rather than a view, and a plain-text terminal mode is a
separate piece of work.

The log file is appended, never truncated, so it holds a history across runs.

```json
{"time":"2026-09-12T14:02:11.412Z","level":"INFO","event":"worker_finished","card":87,"project":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","exit":0,"duration_ms":41207,"output":"moved card 87 to in-progress"}
```

Every line carries `time`, `level` and `event`. Select on `event`:

| `event` | Fields |
|---|---|
| `bridge_started` | `dir`, `max_workers`, `log_file` |
| `connected` | `topic`, `site` |
| `stream_error` | `error` |
| `event_malformed` | `error` |
| `worker_queued` | `card`, `project`, `queue_depth` |
| `worker_refused` | `card`, `project` — that card is already queued or running |
| `worker_started` | `card`, `project` |
| `worker_finished` | `card`, `project`, `exit`, `duration_ms`, `output` |
| `worker_failed` | `card`, `project`, `error` — the process never ran |
| `queue_dropped` | `count`, `cards` |

`queue_depth` counts the accepted events waiting at that moment, the new one
included. `worker_failed` and `worker_finished` name two different faults: a
process that never ran, and a process that ran and returned a non-zero code.

Read a live run with `jq`:

```bash
loupe bridge run --dir ~/Code/my-app | jq -c 'select(.event | startswith("worker"))'
```

### `--permission-mode`

This is the flag that makes an unattended run possible. A worker runs with no
terminal, so it cannot answer a permission prompt. Without the flag, `claude`
denies every tool call that needs approval, and the worker reports what it
could not do. The directive tells the worker to call `card_update`, so leaving
the flag off usually means the card never leaves `next`.

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
