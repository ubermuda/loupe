---
title: "Command-line bridge"
description: "A Go binary that claims the work requests of the workflow and runs a Claude Code or Codex worker for each one. Preview."
---

`cli/` holds a small Go binary that closes the loop. The
[workflow](../using/workflows.md) of a board decides when work runs, and it
opens a work request for each piece of work. The bridge claims a request and
runs it. A worker runs on the harness of its account. On a Claude Code account
it is `claude -p --session-id <uuid> -- <prompt>`, with a new session id for
each worker. On a Codex account it is `codex exec`, as
[A Codex account](#a-codex-account) describes. It reads the card through the MCP, prints its
answer and exits. The bridge reports the exit code and the worker's structured
result, and posts the result of the request. An entry with
`action: interactive` opens an interactive session in a terminal instead, as
[Interactive action](#interactive-action) describes. An entry with
`action: command` runs a command with no agent, as
[Command action](#command-action) describes.
[Installing the CLI](../getting-started/cli.md) says how to install a release,
and `just cli-build` builds one from source. See
[`cli/README.md`](../../cli/README.md) for the commands, the flags and the file
format.

The configuration lives in `rules.yaml`, beside the CLI's `config.json`. Its
`work:` map names each kind of work the bridge runs, and what it runs for it.
The `projects` map gives each project the directory its workers run in. The
bridge refuses to start without the file. A file that still lists `rules:` or
`experiments:` fails to load, with a message that names the work map. The
bridge no longer matches events, so the event filters, `maxChain`, `resume` and
`maxResumes` are gone.

The bridge checks every project slug against the server before it subscribes,
and it stops on a slug you do not own. Its error then lists the slugs you own.
One bridge follows every project you own on one connection. It skips the work
requests of a project `rules.yaml` does not map, and logs that project once.

A project you create while the bridge runs reaches it with no restart. The
bridge skips that project until you map it in `rules.yaml` and run
`loupe bridge reload`. When a mapped project is deleted or stops being yours,
the bridge logs `project_gone` once, and runs no more work for it.

`loupe bridge reload` applies a changed `rules.yaml` to the running bridge. It
reaches the bridge over a local socket, `bridge-<hash>.sock` in the config
directory. The bridge parses the file and checks it against the server, and it
applies the file only when every check passes. A failed reload changes nothing,
and the command prints each problem and exits with status 1. A worker in flight
keeps running. A queued request stays when the new file still runs its kind with
the same action, and the bridge drops and logs the others.

One rule file serves one bridge, so a second `loupe bridge run` on the same file
refuses to start. Its error names the socket of the first bridge.

The `accounts` block of `rules.yaml` names the agent accounts that run the
workers, and `defaults.account` names the one an entry uses when it names none.
[Accounts](#accounts) gives the format, the model order and the permission
levels. A reload reads both again. The `--permission-mode` and `--model` flags
and the instance URL in `config.json` stay fixed until the bridge restarts. The `--max-workers` flag is deprecated and does nothing. Set
`maxWorkers` in `rules.yaml` instead. The bridge logs `max_workers_flag_ignored`
when it starts with the flag.

The optional `name:` key at the top of `rules.yaml` names the bridge. The web
UI shows the name in place of the bridge id. When the key is absent, the bridge
uses the host name of its machine up to the first dot, cut to 40 characters.
The default thus sends the host name to the server with each heartbeat. Set
`name: ""` to send no name, and the server then clears the name it holds. A
name holds at most 40 characters after trimming, and no control character. The
file fails to load for any other name, so the bridge refuses to start and a
reload fails. A reload sends the new name with the next heartbeat. Two bridges
of one account cannot hold one name, as
[The bridge name](../reference/bridge-heartbeat.md#the-bridge-name) says.

The optional `collect:` key at the top of `rules.yaml` turns the
[tool call report](#tool-calls) on or off. It is on when the key is absent.
Set `collect: false`, and the bridge sends no tool call and no timing of any
run. It also takes and sends no [host sample](#host-samples). A reload applies
a change to the key.

A worker entry can also split its runs between models or accounts with
variants, as [Experiments](#experiments) describes.

The bridge authenticates with a token that carries the agent scope. `loupe
login` gets one through the OAuth device flow: it prints a link and a code, and
you choose **Allow** on that page. The CLI then refreshes the access token by
itself. There is no static token, so a machine where nobody can open a browser
cannot run the bridge. See [Connected apps](../using/connected-apps.md) for the
device flow. The token reaches `GET /api/projects`, `GET /api/events`,
`GET /api/projects/{handle}/board/columns`,
`GET /api/projects/{handle}/board/cards/{cardId}`,
`PUT /api/projects/{handle}/worker-runs/{runId}`,
`PUT /api/projects/{handle}/worker-runs/{runId}/tool-calls`,
`PUT /api/projects/{handle}/interactive-runs/{sessionId}`,
`PUT /api/bridges/{bridgeId}/runs`,
`PUT /api/bridges/{bridgeId}/heartbeat`,
`POST /api/bridges/{bridgeId}/work-requests/{workRequestId}/claim` and
`PUT /api/bridges/{bridgeId}/work-requests/{workRequestId}/result`, and no
other endpoint.
The three run endpoints record the states and the tool calls of each worker
run, and the states of each interactive session, and the
[Worker run API](../reference/worker-runs.md) page covers them. The heartbeat
endpoint records that the bridge runs, and the
[Bridge heartbeat API](../reference/bridge-heartbeat.md) page covers it. The
same page covers the two work request endpoints. The firewall refuses a token that carries the
`site-review` or the `mcp` scope.

The handle is a project id or a project slug. A project name does not resolve.
The bridge reads the columns by the slug in `rules.yaml`.

A prompt holds validated identifiers, slugs and pull request values only, and
the bridge adds a fixed line that tells the agent to treat the card as data. A
work request also carries the context of its card: the number, the link and the
head commit of its pull request, the reason for a fix or a repair, and the
document that a revision works on. The bridge checks the shape of each value before it fills a
prompt or a command with it. [The work map](../../cli/README.md#the-work-map)
lists the placeholders.

The bridge is a supervisor. `maxWorkers` in `rules.yaml` bounds the workers
that run at once, three by default, and requests past the bound wait in a
queue. A card runs one worker at a time. A request for a busy card waits and
runs after that worker exits, so a later request for another card can start
first. A waiting request holds no claim, so another bridge can take it first.
Stopping the bridge drops whatever is still queued and logs the count, and each
card with its kind.

`workerPools` in `rules.yaml` splits `maxWorkers` into named pools, and a
worker entry takes its slots from one pool with `workerPool`. The reserved
`default` pool holds the slots the named pools leave, and serves each entry that
names no pool. A request waits only when its own pool is full, so a long run in
one pool never holds back the requests of another. A pool never borrows a free slot of another
pool. A reload that shrinks a pool stops no worker, and only holds back new
starts. Each run report names its pool in `workerPool`, and the heartbeat
reports the size and use of each pool in `workerPools`. The
[`cli/README.md`](../../cli/README.md) rule file reference gives the format and
the checks.

A project rename takes away the slug that `rules.yaml` maps. The bridge reads
`project.renamed` for that reason, and stops the work of the old slug until you
fix `rules.yaml` and run `loupe bridge reload`. A project that a JWT refresh no
longer lists stops its work too. The bridge logs `work_dead` for each. The
bridge names itself by a uuid it keeps in `config.json`.

The bridge reports every run to Loupe, from the moment it claims a work
request. A worker that finishes says so itself, by writing to the card through an MCP tool.
A worker that crashes, that a signal kills, or that never starts writes nothing
at all. A run that waits in the queue, or that the bridge sets aside, has no
worker to write anything. The bridge is the only witness of those runs.

The bridge gives each request it claims a new run id. It sends each state of the
run to `PUT /api/projects/{handle}/worker-runs/{runId}` as the state happens.
One bridge follows several projects, so the handle is the id of the project of
the request. Each log line below goes with the state the bridge reports:

| Log line | State |
|---|---|
| `worker_queued` | `queued` |
| `worker_started` | `running` |
| `worker_finished` | `succeeded`, `blocked`, `unfinished` or `waiting-on-forge` from the status for exit code 0, and `failed` for any other code |
| `worker_no_result` | `no-result` for exit code 0, and `failed` for any other code |
| `worker_failed` | `not-started` |
| `queue_dropped` | `dropped`, with the reason `shutdown`, `rule_dead` or `reload` |

Each report of a work run carries `workRequestId`, `workKind` and `ruleId`, the
id of the template rule that opened the request. A run that a person's resume or
rerun starts carries the kind of the run it continues.

The [Worker run API](../reference/worker-runs.md#the-states-of-a-run) page says
what each state means. The server adds `timed-out` and `lost` on its own. It
also sets `closed` on an interactive run, which no bridge holds.

A clean exit does not prove that the work finished. Every prompt asks for a
structured result. The bridge runs a Claude Code worker with
`--verbose --output-format stream-json` and `--json-schema`. claude prints one JSON line for each
step, and the bridge reads the first line of type `result`. A later `result`
line comes from a turn that a background task notification starts, and the
bridge ignores it. The bridge also reads the single JSON document of
`--output-format json`, so a run that an older bridge started still reports. The core schema requires `status`, which is
`finished`, `blocked`, `unfinished` or `waiting`, and a one-sentence `summary`. A worker with
no valid structured result logs `worker_no_result` at `ERROR`, and its record
carries `hasResult: false`. The stage skills still print a `STAGE RESULT:`
line, and the bridge does not read it. `cli/README.md` covers the schema in
full.

The core schema also takes an optional `reason`, a short code that says why
the run ended. The stage skills set it from the `[reason: <code>]` tag that ends
their `STAGE RESULT:` line. The bridge sends it as `resultReason`. The app keeps
a code it knows, stores `other` for a code it does not know, and stores nothing
when the field is absent. The worker run drawer shows the code. The reason
table in `plugins/loupe/skills/loupe-stage-product-design/references/stage-contract.md`
lists each code.

The core schema also takes an optional `title` and an optional list
`suggestedActions`. The `title` is one line that names the stop. The inbox uses it as the title
of the wait item, and falls back to the first sentence of the report when it is absent. Each
suggested action has an `action` key, a one-line `reason` and an optional `recommended` flag.
The bridge sends both fields in `resultFields`. The app keeps the built-in action keys it
knows, drops the other keys, and keeps three suggestions at most.
The [inbox](../using/inbox.md#titles-and-suggestions-from-the-agent)
shows the title, and uses a recommended suggestion as the main action.
A `run-command` suggestion also carries a `command` and an optional `proposedEntry`. The `command`
has the `entry` name, an optional `branch` and `params`. In the result schema, `params` is a list of
`name` and `value` objects, because the strict schema of Codex cannot hold a map with free keys.
The app also reads a map of names to values. The `proposedEntry` is the YAML of one command entry
without its name, at most 4,000 characters. The app drops a `run-command` suggestion with a
malformed `command`.

A worker reports `waiting` when its work waits on the forge, such as checks on
a pushed pull request. The bridge reports that run as `waiting-on-forge`. The
bridge resumes no run on its own. A run that ends `unfinished` posts `refused`
with its reason. A template whose `retryOn` lists `unfinished` retries the
request. The retry names the session to resume, and the bridge resumes it when this machine holds its
transcript.

A server older than the `unfinished` and `blocked` states refuses them with a
422, and so does a server older than `waiting-on-forge`. The bridge logs `report_failed` for that report and does not
retry it.

The bridge sets `CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS=0` for each worker. Without
it, `claude -p` ends a worker 600 seconds after its main turn when a background
subagent still runs, and exits 0. An operator who sets the variable, even to an
empty value, keeps that value.

Each outcome carries the tokens the worker process spent, per model, as the
`usage` field of the [Worker run API](../reference/worker-runs.md#usage). The
rest of this section covers a Claude Code worker.
[Codex metrics](#codex-metrics) covers a Codex worker. A worker that ends on its own prints `modelUsage` in its result line. The bridge
sends those counts with the source `reported`, and the cost claude computed.

claude's counts cover the whole session, so a resume would count the earlier
processes again. Before a resume starts, the bridge reads the last `cost-state`
line of the session transcript. claude writes that line as each process ends,
with the totals of the session. The bridge subtracts it from the counts the
resume prints, per model and per count, and a count never goes below zero. When
it cannot read the transcript, it sends the whole session marked `estimated`, so
a later reported count can replace it.

A killed process writes no `cost-state` line. claude restarts the session's
totals from the last line, so a resume after a killed process counts only its
own spend. The killed process keeps its own estimated report.

A worker the bridge kills prints nothing, and a worker that crashes can print no
result. The bridge then counts the assistant messages the process wrote to the
transcript after it started, in the session file and in the files of its
subagents. A streamed message repeats its entry, so the bridge counts each
message id once. It sends that sum marked `estimated`. With no transcript, the
run sends no `usage`, and Loupe reads its usage as unknown.

An estimate is low when claude made calls that write no assistant message. On
local transcripts, the sum matched claude's own counts for the main model. It
missed side calls, such as uncached calls to a second model.

The bridge finds the transcript at
`<config>/projects/<directory>/<session id>.jsonl`, where `<config>` is
`CLAUDE_CONFIG_DIR` or `~/.claude`. Subagent transcripts are in
`<session id>/subagents/` beside it. The bridge keeps the baseline in the run
record, so a run that a new image [adopts](#the-handover) reports the same way.

An estimate takes its cost from a price table in the bridge,
`cli/internal/transcript/prices.go`. The table holds Anthropic's list prices per
million tokens for input, output and cache reads, and the date they were read. A
cache write costs 1.25 times the input price for the five-minute cache, and 2
times for the one-hour cache. A model that is not in the table has a null cost,
and web search requests have no cost.

The bridge drops a usage the server would refuse, such as one with more than 20
models, and logs `usage_dropped`. The outcome still goes out.

`loupe usage backfill` sends the usage of worker runs that ended before the
bridge captured usage. It reads the `worker_started` lines of the bridge log
and the transcript of each session. It sends each session once, to the
[session usage report](../reference/worker-runs.md#reporting-the-usage-of-a-session),
with the login of `loupe bridge`. Each `worker_started` line is one process of
its session. The process ends at the first `worker_finished`, `worker_no_result`
or `worker_failed` line after it with the same card and rule, because a card
runs one worker at a time.

claude can write more than one `cost-state` line in a process, and each line
holds the session totals. A line belongs to the process that wrote the last
timed transcript entry above it. A process that ended on its own spent its last
line minus the line above its first line. The command sends that difference and
claude's own dollars, marked `reported`. A process with no line, or with timed
entries after its last line, was killed. The command sends the priced sum of its
messages up to its end, marked `estimated`.

A line or a message outside every process belongs to no process. That covers
the lines above the first process, and a resume by hand between the end of one
process and the start of the next. A process with no end line runs to the start
of the next. The command then prints a `warning` line for its session, because
the process can still run.

Run the command on the machine that ran the bridge, before claude deletes the
transcripts after about 30 days. Run it when the bridge has no worker in
flight, because a running process sends only what it spent so far. The command
skips a session that has no transcript, a run with no card, and a session
whose totals go down or do not follow the start order. `cli/README.md` lists
its flags and its output.

Each time the bridge connects to the hub, it sends the runs it holds to
`PUT /api/bridges/{bridgeId}/runs`. A held run is one whose last state is
`queued`, `preparing`, `running` or `stopping`. Loupe marks `lost` each open or timed-out run
of that bridge that the list does not name. The bridge keeps its id across restarts, so
Loupe closes the open runs of a bridge that died when it next connects. The
inventory goes out after every state the
bridge queued before it.

Run reports and heartbeats go through one outbound queue, held in memory. Each
kind has its own delivery policy, and the kinds never wait on each other. Run
reports go out in order. A failed send waits one second, then
twice as long before each later attempt, up to sixty seconds. The bridge gives
up after ten attempts and logs `report_failed`.

When the bridge gives up on the closing state of a worker run, such as `failed`,
because Loupe was not reachable, it writes that report to an outbox directory
beside its cursor file and logs `report_kept`. The next connect sends the kept
reports, oldest first, before the run inventory. They take one place in the
queue together, and a retry starts at the first report Loupe did not answer.
The queue keeps two places for the kept reports and the inventory, so a queue
that an outage filled still takes them. A connect queues neither again while an
earlier copy still waits.
The bridge deletes a file when Loupe takes or refuses its report. A report that Loupe refused is never kept. A kept report that is
older than seven days is deleted, and the bridge logs `report_dropped`. A report
made for another Loupe instance or bridge id stays on disk and is never sent.
The result of a work request stays in memory only.

The bridge logs `report_folded` when Loupe answers 200 to a report the bridge
sends for the first time. For a state report, Loupe already held that state of
the run, so the report changed nothing.

Loupe answers 404 on both PUT endpoints when agent push is switched off. The
bridge reads a 404 as a refusal, as it reads every 4xx answer other than 408
and 429. It sends a refused report once, logs `report_failed`, and drops it.

Stopping the bridge with `Ctrl-C` or `SIGTERM` kills its workers, and those runs
are the ones only the bridge can report. An [update](#updates) kills no worker. So it gives each report one last attempt, in a window of five
seconds. It logs `report_dropped` with the count of the reports that miss the
window. A missing record therefore means "unknown", and never "the worker did
not run".

The bridge sends a heartbeat to `/api/bridges/{bridgeId}/heartbeat` once at
start and then at the interval that `bridge.heartbeat_interval_seconds` gives,
60 seconds by default. The heartbeat names the projects the rule file maps and
the version of the bridge, and the state of its [update](#updates). The server
answers with the range of CLI versions it supports. A reload sends a heartbeat
at once with the new projects. The heartbeat has a latest-wins lane in the outbound
queue. A newer heartbeat replaces one that has not gone out, and a failed one
waits for the next interval. A slow or failing heartbeat never delays a run
report. A server with no heartbeat endpoint answers 404, and the bridge logs
`heartbeat_unsupported` once and keeps working.

There is no terminal UI. The bridge writes one JSON object per line to stdout
and to its log file, named by `--log-file`. Each line carries a stable `event`
key, so `jq` selects what you want. The log file is appended, so it is a history
across runs.

The bridge logs `connected` when the hub answers with an event stream, and
`stream_error` when a connection fails. A hub, proxy or login page that answers
200 with something other than `text/event-stream` is a failure. The error names
what arrived, such as `hub answered 200 with text/html (325 bytes), expected
text/event-stream`, and never the body. A stream that ends before the hub sent
any line, a heartbeat included, is a failure too. After a failure the bridge
waits before it connects again. The wait starts at 1 second and doubles up to 30
seconds. Only a stream that delivered a line resets it to 1 second.

The bridge logs `stream_closed` when a stream that delivered a line ends with no
error. `lived_s` is how long the stream stayed open, in seconds, and `events` is
the number of events it received. The hub closes each subscription on purpose
before its write timeout ends, so a clean close is normal. The hub of Loupe keeps
a subscription open for up to one hour. The subscriber token of the bridge also
lives one hour, so expect a `stream_closed` line every 48 to 60 minutes. The
bridge then connects again at once and catches up on what it missed.

The bridge logs `event_dropped` for an event it receives and does not act on.
`reason` says why. `unknown_type` is a type this bridge version does not know,
and the bridge logs it at warning level once per type, so a person knows to
update the bridge. `other_bridge` is a command for another bridge of the same
account, such as the answer to an ask of a session on another machine. The
bridge logs it at info level each time.

The bridge needs a Mercure hub to have anything to subscribe to.

## Accounts

An account is one login of an agent tool that runs workers. The `accounts`
block of `rules.yaml` names each account, and `defaults.account` names the
account of an entry that names none. A work entry and a variant of an
[experiment](#experiments) can set `account` to use another one. This account
is the agent login, and [Agent account](#agent-account) is the GitHub user
that pushes.

```yaml
envFile: ~/loupe/common.env

accounts:
  claude:
    harness: claude-code
    model: opus
  work:
    harness: claude-code
    configDir: ~/.claude-work
    model: claude-sonnet-5-5
    permissionMode: auto
    envFile: ~/loupe/work.env

defaults:
  account: claude
  permissions: workspace

work:
  tech-design:
    prompt: Use the loupe-stage-tech-design skill for card {cardNumber}.
  implement:
    account: work
    permissions: full
    prompt: Use the loupe-stage-implementation skill for card {cardNumber}.
```

| Field | Required | Purpose |
|---|---|---|
| `harness` | yes | The agent tool of the account, `claude-code` or `codex` |
| `configDir` | no | The Claude Code config folder of the account. The bridge sets `CLAUDE_CONFIG_DIR` to it for each run. A `codex` account refuses it |
| `codexHome` | no | The Codex home folder of a `codex` account. The bridge sets `CODEX_HOME` to it for each run. A `claude-code` account refuses it |
| `profile` | no | The Codex profile of a `codex` account, which names the file `<profile>.config.toml` in the Codex home folder. A `claude-code` account refuses it |
| `model` | no | The model of the account's runs, when the entry or the variant names none |
| `permissionMode` | no | A permission mode of the harness, when the entry or the variant names no level. For `codex` it is `read-only`, `workspace-write` or `danger-full-access` |
| `envFile` | no | An [environment file](#environment-files) that each run of the account reads |

An account name is 1 to 40 lowercase letters, digits and hyphens, and starts
with a letter. A file declares at most 50 accounts. `configDir`, `codexHome` and `envFile` are absolute paths or start with `~/`. A `profile` is 1 to 64 letters, digits, dots, underscores and hyphens, and starts with a letter or a digit.
Neither path has to exist when the file loads. `defaults.account` is required,
and it names an account of the block. The bridge refuses the file at start, and
a reload fails, when an entry names an account that the block does not declare.
The keys `defaults.model` and `defaults.permissionMode` and the `permissionMode`
of an entry are gone, and a file with `accounts` refuses them.

### Model and permissions

A run takes the first model that this list sets:

1. the `model` of the variant or the entry
2. the `model` of the account
3. the `--model` flag of `loupe bridge run`
4. the default of the harness, because the bridge passes no `--model`

`permissions` sets a permission level, on an entry, on a variant or in
`defaults.permissions`. The harness maps each level to a mode of its own:

| Level | Claude Code mode | Codex sandbox |
|---|---|---|
| `read-only` | `plan` | `read-only` |
| `workspace` | `auto` | `workspace-write` |
| `full` | `bypassPermissions` | `danger-full-access` |

A worker run takes the first mode that this list sets:

1. the level of the variant or the entry
2. the `permissionMode` of the account, which is a native mode of its harness
3. the level in `defaults.permissions`
4. the `--permission-mode` flag of `loupe bridge run`

A worker on a `codex` account that none of these set runs in the `workspace`
sandbox. The own default sandbox of Codex has no network, so a worker could not
reach the forge.

The `--model` and `--permission-mode` flags name Claude Code values. A run on a
`codex` account ignores both.

A variant with no `account` or no `permissions` takes the value of its entry.
An [interactive entry](#interactive-action) takes its mode from its own
`permissions` only. The model order is the same for it. The bridge log names
the mode that ran in the `permission_mode` field. The `worker_started` line
holds it for an entry with no variants, and `worker_variant` holds it for an
experiment. The `run.json` file of the run holds it as `permissionMode`. The run page in
Loupe does not show it.

Each run records its harness, its account and its model in Loupe. That holds
for worker runs and for interactive runs.

### Environment files

The top-level `envFile` and the `envFile` of an account name files of
environment variables for each agent run. A file holds one `KEY=VALUE` pair on
each line. A line can start with `export `, and a line that starts with `#` is
a comment. The bridge removes one pair of matching quotes around a value, and it
expands nothing.

The bridge reads the files at the start of each run, so a change needs no
reload and no restart. It reads the global file first, then the file of the
account, so the account value wins. A missing file or a bad line fails the run
before it starts. The reason names the file and the line, and never a value.

An environment file cannot set the config folder variable of its harness, which
is `CLAUDE_CONFIG_DIR` for `claude-code` and `CODEX_HOME` for `codex`. The run
fails when one does. Set `configDir`, or `codexHome`, on the account instead. An interactive launch writes the
variables into its launch script, because the terminal does not take the
bridge's environment. The script is readable by its owner only, and it deletes
itself when it runs.

### A second Claude Code account

Claude Code keeps a separate Keychain login for each config folder. So each
account with its own `configDir` logs in once. Run
`CLAUDE_CONFIG_DIR=~/.claude-work claude` with the folder of the account. Then
type `/login` in that session.

Claude Code also reads its MCP servers and its skills from the config folder.
Add the `loupe` MCP server to each config folder. Install the Loupe skills in
each config folder too. Otherwise a worker of that account cannot reach Loupe.

### A Codex account

An account with `harness: codex` runs its workers with `codex exec`. The bridge
is tested with Codex 0.155.1, and needs `codex` on the `PATH` of the account.

```yaml
accounts:
  openrouter:
    harness: codex
    codexHome: ~/.codex
    profile: openrouter
    model: openrouter/free
    envFile: ~/loupe/openrouter.env
```

The profile `openrouter` is the file `~/.codex/openrouter.config.toml`:

```toml
model = "openrouter/free"
model_provider = "openrouter"

[model_providers.openrouter]
name = "OpenRouter"
base_url = "https://openrouter.ai/api/v1"
env_key = "OPENROUTER_API_KEY"
wire_api = "responses"
```

The profile names the variable in `env_key`. Put that variable in the
`envFile` of the account, such as `OPENROUTER_API_KEY=...`. An account with no
`profile` uses the login of the Codex home folder, which `codex login` makes.
A profile whose provider uses the OpenAI sign-in also uses that login.

The bridge passes the result schema to Codex with `--output-schema`, in the
strict form that the OpenAI API needs. Each object sets `additionalProperties`
to `false` and lists every property as required. An optional field, such as
`reason` or a field of `resultFields`, also takes `null`. The bridge reads a
`null` field as a field the worker did not set.

Each permission level maps to a sandbox of Codex. The `workspace` level
turns the network on. It also adds the git folder of the main repository as a
writable folder, because a worker in a git worktree keeps its index and refs
there. The `read-only` level turns the network off. The `full` level starts
Codex with `--dangerously-bypass-approvals-and-sandbox`, so Codex runs no
sandbox.

Codex does not read the Loupe tools until you add the MCP server to the Codex
home folder. Run this once for each Codex home folder:

```sh
CODEX_HOME=~/.codex codex mcp add loupe -- loupe mcp
```

The [account check](#account-checks) fails when the server is missing. A worker
without the Loupe tools cannot read its card.

Codex starts an MCP server with a fixed set of environment variables, so
`LOUPE_SESSION_ID` on the `codex` process does not reach `loupe mcp`. The bridge
therefore passes the run id with `-c mcp_servers.loupe.env.LOUPE_SESSION_ID=<run id>`,
on a worker, a resume and an interactive launch. That key adds one variable, and
keeps the `env` and `env_vars` that your config sets. Without it, Loupe refuses a
tool that only a worker of the card can call, such as `agent_review_submit`.

`codex exec` runs with the approval policy `never`. At the `workspace` and
`read-only` levels, Codex refuses a Loupe tool call that needs approval. Add
this line by hand under `[mcp_servers.loupe]` in the profile file or in
`config.toml` of the Codex home folder:

```toml
[mcp_servers.loupe]
default_tools_approval_mode = "approve"
```

The check reads the home folder only. It does not read a `.codex/config.toml` in a
project, because Codex does not print the effective approval mode. A project file that
sets another mode can still block the Loupe tools.

A run at the `full` level works without the line. The account check still asks for it,
because the check does not know the levels of the rules. The bridge passes no approval override.

After each run, the bridge checks that Codex used the provider that the profile
names. A run on another provider fails with the message `codex ran on provider X
but profile P names Y`.

#### Prices from OpenRouter

The bridge prices a Codex run only for a model that its price table lists. A
model that the table lacks has an unknown cost. Add `openRouterPrices: true` at
the top of `rules.yaml` to price the models of OpenRouter too:

```yaml
openRouterPrices: true
```

The key is off when absent. Once on, the bridge reads the public list at
`https://openrouter.ai/api/v1/models`. It needs no key, and the host of the
bridge needs egress to `openrouter.ai`. A fetch times out after 10 seconds.

- The bridge saves the list in `openrouter-prices.json` in the config folder.
- A saved list younger than 24 hours replaces the call, so the bridge makes at most one call a day.
- The bridge checks the list at start, after a reload, and once an hour.
- A failed fetch never stops the bridge. The bridge uses an older saved list when one exists. Otherwise the cost of the model stays unknown.
- A model of the built-in table keeps its built-in price.
- A reload that removes the key empties the fetched prices, and the cost of those models is unknown again.
- A tier that omits the cache price keeps the cache price of the base tier.

Some models charge more for a long prompt. The list gives such a model a price
for each size of prompt. The bridge prices each reply at the price for the size
of its own prompt. A free model costs 0, which is not the same as unknown.

The bridge gives each run its own id, and Codex picks its own thread id. The
bridge keeps the pair in the `codex-threads` folder of its config folder, so
`resume` continues the right thread.

#### Codex metrics

A Codex run reports the same metrics as a Claude Code run. The bridge reads
them from the session file of the run in `<codexHome>/sessions/`, and from the
session file of each subagent, which Codex writes beside it:

1. The tool calls are the function calls and the custom tool calls of the
   files. A call of a subagent counts as made by a subagent.
2. The signatures of a shell call come from the commands that Codex ran inside
   it.
3. A `spawn_agent` call lasts until the last line of the session file of its
   subagent.
4. The peak context is the largest input of one reply of the main thread.
5. The tokens and the cost add the tokens of each subagent.

Codex does not document the session file, so a new Codex release can change
it. When the bridge cannot read the file of the run or of one subagent, the
tool calls, the timing and the peak context of the run are unknown, never zero.
The tokens then come from the total that Codex prints, which leaves out the
subagents.

Limits of this release:

- The cost of a Codex run is empty for a model that no price list holds, as
  [Prices from OpenRouter](#prices-from-openrouter) says.
- The `effort` of a work request does not reach Codex.

### Account checks

The bridge checks each account that the `accounts` block of `rules.yaml`
declares, when it starts and on each `loupe bridge reload`. It also checks an
account that no rule uses. A check of a Claude Code account asks three
questions:

1. Is `claude` on the `PATH` of the account, which an env file can set? A
   relative `PATH` entry counts from each project folder, as it does for a
   worker.
2. Does `claude auth status` pass with the environment of the account? That
   environment is the env files of the account and its `CLAUDE_CONFIG_DIR`. A
   key such as `ANTHROPIC_API_KEY` in an env file also passes.
3. Does Claude Code see the `loupe` MCP server and the Loupe skills in each
   project folder? The server is seen when the project or the account's
   `.claude.json` declares it as `loupe mcp`, or, when neither declares it, an
   enabled plugin serves it. A declared entry that starts another command
   fails, because Claude Code prefers it to a plugin. A declared entry also
   fails when `loupe` is not on the `PATH` of the account. The skills are seen
   when a `loupe-*` folder with a `SKILL.md` is in `.claude/skills` of the
   project or in `skills` of the config folder, or when an enabled `loupe@`
   plugin is installed.

A check of a Codex account asks these questions:

1. Is `codex` on the `PATH` of the account?
2. Does the `codexHome` folder exist, when the account sets one?
3. Does the profile file exist, when the account sets a `profile`? If it does,
   the variable named by `env_key` in the profile must hold a value in the
   environment of the account.
4. Does `codex login status` pass? The check asks when the account sets no
   `profile`. It also asks when the profile names no `env_key` and its provider
   uses the OpenAI sign-in. That is the default `openai` provider, or a provider
   table with `requires_openai_auth = true`. A local provider such as `ollama`
   needs no login.
5. Does Codex see the `loupe` MCP server in each project folder? The check runs
   `codex [-p profile] mcp get loupe --json` there.

The MCP check fails in these cases:

- The account has no `loupe` server. Run
  `CODEX_HOME=<home> codex mcp add loupe -- loupe mcp`.
- The server is not `loupe mcp` over stdio, for example a server with a URL.
- The server is disabled.
- `loupe` is not on the `PATH` of the account.
- The command takes more than 10 seconds.
- The server has no `default_tools_approval_mode = "approve"` under
  `[mcp_servers.loupe]`. Add the line by hand, as
  [A Codex account](#a-codex-account) describes.

A failing account turns off its own entries only. An entry is off when its
account, or the account of one of its variants, fails. Every other entry keeps
running, and a request for an entry that is off waits, as it does when no
bridge takes it. The log line `account_failed` names the account, the reason
and the detail. The heartbeat sends each account with its harness, its state
and a short reason, and the Agents page in Loupe shows them. A path, an email
or a key never leaves the machine. The bridge checks again only on a reload,
so run `loupe bridge reload` after you fix an account. `loupe status` runs the
same checks.

The Agents page and `loupe status` mark an account that no rule uses as unused.
A failing unused account turns off no rule. Only a failing account that a rule
uses makes `loupe status` exit with a non-zero code.

### Migration and rollback

At each start, the bridge gives an account to a `rules.yaml` with no `accounts`
block. It adds the account `claude` with `harness: claude-code`, and sets
`defaults.account: claude`. The old `defaults.model` and
`defaults.permissionMode` move into that account. The `permissionMode` of each
entry becomes `permissions`:

| Old entry mode | Level |
|---|---|
| `plan` | `read-only` |
| `acceptEdits` | `workspace` |
| `auto` | `workspace` |
| `bypassPermissions` | `full` |

Any other entry mode stops the migration. The migration changes only those
lines, so comments and blank lines stay. The bridge logs
`accounts_migration_done` when it writes the file.

When the migration stops or cannot write the file, the bridge logs
`accounts_migration_failed`. The `block` field of that line holds the block to
paste. The bridge then runs with its worker entries, its interactive entries
and its app prompts off. It logs `agents_off` with the reason. Command entries
and hooks still run. Paste the block into the file. Change each entry
`permissionMode` to `permissions`. Then run `loupe bridge reload`.

An older CLI refuses a file with an `accounts` block. To roll back, keep a copy
of the file from before the upgrade. You can also remove the `accounts` block
and `defaults.account`, and put back the old lines by hand.

### Resume

A resume that a person or a closed inbox ask sends uses the account that the
run started on. It also keeps the model of that run. The bridge refuses the
resume when `rules.yaml` no longer holds that account. It also refuses it when
the account now names another harness. The reason names the account. An
automatic continuation of an unfinished run uses the account that the rule
names now.

## Expired login

A running bridge that finds its login expired does not retry every 30 seconds.
The first error puts the whole bridge in one waiting state. It writes one
`login_expired` event to the log with the message "Your Loupe login expired.
Run `loupe login` in another terminal. The bridge resumes by itself." On macOS
it also shows one notice, "Loupe bridge needs a new login". The notice needs
notifications for your terminal to be on in System Settings.

While it waits, the bridge sends no heartbeat, starts no new claim and spends no
report retry. Workers that already run keep running. The bridge looks at
`config.json` every 5 seconds. When `loupe login` writes a new login, the bridge
writes a `login_resumed` event and carries on with no restart. A bridge that
starts with an expired login still exits, and the error tells you to run
`loupe login`.

## Tool calls

The harness adapter of the bridge reads each tool call of a worker. For Claude
Code, it reads the stream on claude's stdout. For Codex, it reads the session
file of the run and the session file of each subagent, as
[Codex metrics](#codex-metrics) says. When the worker ends, the bridge sends the calls after the final state of the
run, to `PUT /api/projects/{handle}/worker-runs/{runId}/tool-calls`. One
request holds at most 500 calls. The last request also holds the tool time and
the idle time of the run, so a run with no call still sends one request.
[Reporting the tool calls of a run](../reference/worker-runs.md#reporting-the-tool-calls-of-a-run)
gives the fields.

A call holds its tool, its start, its duration, its error flag, and whether a
subagent made it. A call with no timestamp in the stream stays out. A call that
starts a background task holds the id of that task, and a later call whose
input names that id waits on it. A call that starts a subagent lasts until the
last line of its subagent, so a background subagent counts in full.

The adapter gives each call a kind. A call that runs shell commands is `shell`,
such as a Claude Code `Bash` call. A call that starts a subagent is `subagent`,
such as a Claude Code `Agent` or `Task` call or a Codex `spawn_agent` call. Any
other call is `tool`. The server reads the kind and never the tool name.

A call also holds signatures, which name what it ran. A `shell` call gets one
signature for each program its command runs, such as `grep` or `git status`.
A signature keeps the base name of the program and no argument. A program on
the subcommand list also keeps its second word, when that word is a lowercase
word of up to 31 characters. Any other call gets its tool name. A call holds at
most 20 signatures.

The `insights.subcommand_programs` feature flag holds the subcommand list, as a
comma list, and you change it at **`/admin/feature-flags`**. Its default is
`git,just,npm,pnpm,yarn,cargo,go,docker,gh,composer,make,pip,uv`, and an empty
value reads as the default. The flag is one list for the whole instance.
`GET /api/projects` gives the list to the bridge as `subcommandPrograms` on
each project. The bridge keeps the answer for 10 minutes. When it cannot read
it, the bridge uses the default list.

`GET /api/projects` also gives `collectFullText` on each project. The bridge
sends the full input text of a call only when that value is `true`. The
project setting **Collect the full text of each tool call** turns it on, in the
analysis settings on the
[Reports](../using/analytics.md#reports) tab. It is off by default.

A server with no tool call endpoint answers 404 with no error code, and so does
a server with agent push switched off. The bridge then logs
`tool_calls_unsupported` once. It drops that batch with no retry. The batches
of later runs still try, so the calls come back when agent push comes back on. Set `collect: false` in `rules.yaml` to send no tool call and no
timing at all.

## Host samples

The bridge can sample the machine it runs on. A sample holds the use of each
CPU core, the memory in use and in total, the swap in use, the battery charge
and the power source. The bridge keeps up to 720 samples, and sends at most
60 of them with each
[heartbeat](../reference/bridge-heartbeat.md#host-samples), oldest first. The server uses
them for the host metrics of each run, as
[Run metrics](../reference/worker-runs.md#run-metrics) describes.

The bridge reads the CPU, the memory and the swap through gopsutil. It skips a
sample when one of them cannot be read. The battery source depends on the
system:

| System | Source |
|---|---|
| macOS | the output of `pmset -g batt` |
| Linux | the `power_supply` class under `/sys/class/power_supply`. A battery of a device such as a mouse does not count |
| other | none, so the charge and the power source are unknown |

A machine with no battery sends no charge. A power source that the bridge
cannot read is unknown, and the server stores it as `null`.

Two feature flags control the samples. Change them at
**`/admin/feature-flags`**:

| Flag | Default | Meaning |
|---|---|---|
| `bridge.host_sampling_enabled` | off | the bridges of the instance take samples |
| `bridge.host_sample_interval_seconds` | 60 | the seconds between two samples. A value below 5 reads as 60 |

To turn the samples on:

1. Open **`/admin/feature-flags`** as an admin.
2. Switch on `bridge.host_sampling_enabled`.
3. Optionally, set `bridge.host_sample_interval_seconds`.
4. Wait for each bridge to reconnect, or run `loupe bridge reload` for it.

The bridge reads both flags from the [events endpoint](#events-endpoint) at
start, at each reconnect and at each `loupe bridge reload`. A bridge with
`collect: false` in its `rules.yaml` takes no sample, whatever the flags say.

## Agent account

`loupe agent-account set` stores the token of a separate GitHub user for
agents in `config.json`. Each worker then pushes as that user. The token
stays on the machine, and Loupe never receives it. `show` prints the login and
the id, and `clear` removes the account.

When the bridge starts, it calls `GET /user` on the GitHub API with the stored
token. It sends the login that GitHub gives as `pushLogin` in each heartbeat.
With no account, the bridge sends `""`. When the call fails, the bridge logs
`agent_account_check_failed` and sends `""`, so a revoked token never shows as
set up. The bridge does not check again until it restarts.

Each worker gets these variables when an account is stored. The bridge
first removes the inherited `GH_TOKEN`, `GITHUB_TOKEN`, `GIT_AUTHOR_NAME`,
`GIT_AUTHOR_EMAIL`, `GIT_COMMITTER_NAME` and `GIT_COMMITTER_EMAIL`.

| Variable | Value |
|---|---|
| `GH_TOKEN` | The stored token, which `gh` reads |
| `GIT_AUTHOR_NAME`, `GIT_COMMITTER_NAME` | The login |
| `GIT_AUTHOR_EMAIL`, `GIT_COMMITTER_EMAIL` | `<id>+<login>@users.noreply.github.com` |
| `GIT_CONFIG_KEY_n`, `GIT_CONFIG_VALUE_n` | Two `credential.https://github.com.helper` entries and two `url.https://github.com/.insteadOf` entries |
| `GIT_CONFIG_COUNT` | The inherited count plus four |

The first helper entry is empty, which removes every helper that your git
configuration names for `github.com`. The second helper answers with the login
and `$GH_TOKEN`. The two `insteadOf` entries change a GitHub SSH remote,
`git@github.com:` or `ssh://git@github.com/`, to HTTPS. A worker then pushes
with the token, never with the SSH key of the machine. A rule in your own git
configuration that changes `https://github.com/` to SSH, with `insteadOf` or
`pushInsteadOf`, still wins over these entries. Remove such a rule on the
machine of the bridge. An inherited `GIT_CONFIG_COUNT` keeps its entries, and the
bridge numbers its own entries after them. A before command and a command
action keep the bridge's own environment, so they push as you.

## Experiments

An experiment splits the cards of a kind of work between models or accounts.
A worker entry of the [work map](#work-requests) lists its `variants`, and its
kind names the experiment. Each variant has a `name`, a `weight`, and a `model`
or an `account`. A variant can also set `permissions`. The entry sets no
`model`:

```yaml
work:
  implement:
    prompt: Implement card {cardNumber}.
    variants:
      - name: opus
        weight: 1
        model: opus
      - name: sonnet
        weight: 1
        model: claude-sonnet-5-5
```

A variant with no `model` takes the model of its account, as
[Model and permissions](#model-and-permissions) says. A variant with no
`account` or no `permissions` takes the value of its entry. A variant's weight
sets its share of the cards, so weights of 3 and 1 give the first variant three
cards in four.

An entry with variants can also list its `metrics`. Each key is a metric key
that the `metric_list` MCP tool names, such as `cost` or `merge-rate`:

```yaml
work:
  implement:
    prompt: Implement card {cardNumber}.
    variants:
      - {name: opus, weight: 1, model: opus}
      - {name: sonnet, weight: 1, model: claude-sonnet-5-5}
    metrics: [merge-rate, cost, duration]
```

The Comparison tab of the experiment shows the declared metrics in that order.
An entry with no `metrics` shows the default six. The bridge sends the list
with each pin request, so the latest list wins. The bridge does not know the
metrics of the server, so it checks the shape of each key only. The server
shows a note for a key that it does not know.

The bridge refuses the file, at start and on a reload, when:

- an entry sets both `model` and `variants`
- an interactive entry or a command entry sets `variants` or `metrics`
- an entry sets `metrics` and no `variants`
- an entry lists more than 16 metrics, or one metric twice
- a metric key does not match `^[a-z][a-z0-9:-]{0,63}$`
- an entry has no variants, or more than 32
- a variant has a weight below 1 or above 1,000,000
- a variant sets neither `model` nor `account`
- a variant has a model longer than 100 characters, with whitespace or with a
  control character
- a variant names an account that the `accounts` block does not declare
- two variants of one entry share a name
- a variant name does not match `^[a-z0-9][a-z0-9_-]{0,63}$`

Before a worker starts, the bridge draws a candidate variant. It hashes the
kind and the card id onto the weights, so every bridge draws the same candidate
for a card. The bridge then asks the server for the pin of the card, through the
[experiment pin endpoint](../reference/worker-runs.md#resolving-an-experiment-pin).
The server keeps the first pin of each card, so a card keeps its variant on
every later run of the kind, resumes included. Each pin request also sends the
weight of each variant. The server keeps the latest weights of each experiment.

A change to the weights moves only the cards that have no pin yet. When you
remove a variant, its cards take the candidate on their next run. That run
records the old variant in `switchedFrom`.

The pin request has a timeout of 10 seconds. When it fails for any reason, the
bridge runs the candidate and logs `experiment_pin_failed` at level `WARN`. A
network error, an answer other than 200 and a server with no pin endpoint are
examples.

The `running` report and the outcome of the run carry `experiment`, `variant`,
`requestedModel` and `switchedFrom`. The
[Worker run API](../reference/worker-runs.md#reporting-a-run-state) page gives
their rules. A live run keeps its variant through an [update](#updates).

A card keeps its variant only in the kind that has the variants. Another kind
with its own `model:` runs that model on the card. The
[Experiments](../using/experiments.md) tab of the project's **Analytics** page
compares the variants of each experiment. To end an experiment, give the entry a
plain `model:` again, and remove its `variants:`. Then run `loupe bridge reload`.

## Updates

A release build of the bridge can update itself. Automatic updates are off by
default, and `autoUpdate: true` in `rules.yaml` turns them on. A development
build, such as one from `just cli-build`, has no version. It never updates, and
it logs `update_skipped` at start.

### The check

The server declares the CLI versions it supports as a caret range, `^1.0` today,
and sends it in its answer to each [heartbeat](../reference/bridge-heartbeat.md).
The bridge checks for a release after its first heartbeat, again when the range
changes, and then every hour plus a random delay of up to 10 minutes. A server
that sends no range starts no check.

A check reads the releases from
`GET https://api.github.com/repos/ubermuda/loupe/releases`, 100 to a page and
newest first. Server releases share that list, so the check reads every page,
up to 10 pages. It picks the highest release that meets all of these
conditions:

- The release is not a draft or a prerelease, and its tag has the form
  `cli/vX.Y.Z`. The bridge skips every other tag, such as a plain `vX.Y.Z`.
- The version is inside the range.
- The version is not on the skip list.
- The release has the archive for this OS and CPU, and `checksums.txt`.

The bridge installs that release when the running version is lower, or when the
running version is outside the range. A bridge above the range therefore
installs a lower version. When the running version is outside the range and no
release fits, the bridge logs `update_unavailable` once and keeps running. The
agents page then shows the chip "Needs ^1.0".

The bridge downloads the archive and `checksums.txt`, and compares the SHA-256
of the archive with its line in `checksums.txt`. A mismatch logs
`update_rejected`, and the bridge installs nothing. A match logs
`update_verified`, and the bridge writes the binary to
`versions/<version>/loupe` in its config directory. A later check uses that
file again when it is still there.

Automatic updates are off unless `rules.yaml` holds `autoUpdate: true`. With
them off, the check stops before the download. The bridge logs
`update_available` once for each version and installs nothing. The agents page
then shows "Update available" and the version, with the command that installs
it. That command is `brew upgrade loupe` for a Homebrew install, and
`curl -fsSL https://<your Loupe>/install.sh | sh` in all other cases.
A bridge that runs a Homebrew binary acts as if updates are off, even with
`autoUpdate: true`, because only `brew upgrade loupe` replaces that binary.

`loupe update auto on` or `loupe update auto off` sets the key. It adds the key
when the file has none, and changes a plain `true` or `false` on its line. When
it cannot change the line alone, it exits with status 1 and names the line to
edit. With `--keep`, a key that the file holds keeps its value. See
[`loupe update auto`](../../cli/README.md#loupe-update-auto).

An older CLI took a missing key as on. So the first start after a handover from
such a CLI adds `autoUpdate: true` to the rule file and logs
`auto_update_migrated`. `update.json` keeps this record for each rule file.
`defaultOff` lists each rule file that a new CLI started with, and no later
start of that file changes it. A start that is not a handover only adds the
file to `defaultOff`. When the file takes no new last line, the bridge logs
`auto_update_migration_failed` with the line to add by hand. It then adds the
file to `autoUpdatePending` and keeps updates on. Each later start of that rule
file, a handover or a plain restart, keeps updates on and tries again.

### Blocked

The bridge must be able to write the directory that holds its binary, after it
resolves symlinks. When it cannot, it logs `update_blocked` and the agents page
shows "Update blocked". This happens, for example, when the binary is in
`/usr/local/bin` and belongs to root. Move the binary to a directory you own,
such as `~/.local/bin`.

### The handover

The bridge replaces itself in the same process. These are the steps:

1. The bridge runs the new binary as `loupe bridge preflight`, with a limit of
   30 seconds. The new binary reads the rule file, runs the start checks
   against the server and calls `GET /api/events`.
2. The bridge pauses. It starts no worker, and new requests wait in the queue.
3. The bridge waits up to 10 seconds for its run reports, its claims and its
   launches to go out. When they do not finish, the bridge resumes, logs
   `update_deferred`, and tries again at the next check. A reload in progress
   also defers the update.
4. The bridge writes its state to `handover-<hash>.json` in its config
   directory: the queue, the workers in flight, the chain counts and the id of
   the last event it read. It logs `update_handover`.
5. The bridge runs the new binary through `exec`. The process keeps its pid, the
   workers stay its children, and the lock and the control socket stay open.

The new version reads the state and takes over each worker, which logs
`worker_adopted`. It connects to the hub with the last event id, and the hub
replays what it published during the handover. The bridge drops each replayed
event it already handled, and logs `event_duplicate`.

A worker writes its output and its exit code to files, so the new version can
read how a worker ended that it did not start. Each worker has a directory
`runs/<runId>/` in the config directory, which holds `run.json`, `stdout`,
`stderr` and `status.exit`. The bridge deletes the directory after it reports the run.

The new version is healthy when its stream connects and one heartbeat lands,
both within 60 seconds. It then logs `update_applied`, and the agents page shows
"Up to date". It copies itself over the installed binary, through a rename in
the same directory, under `update.lock` in the config directory. It logs
`update_installed` when the installed binary changed. It deletes the staged
binaries except the new and the old version.

### Rollback

A new version that is not healthy in 60 seconds, or that fails to start, logs
`update_unhealthy`. It then runs the old binary through `exec`, with its current
state. When the old binary is healthy again, it logs `update_rolled_back` with
the reason `health`. The agents page shows "Rolled back from" and the version.
When run reports do not finish within 10 seconds, the new version logs `update_rollback_deferred`, keeps running and
waits another 60 seconds for its health.

A failed preflight or a failed `exec` also logs `update_rolled_back`, with the
reason `preflight` or `exec`. The old version then never stopped.

Some preflight failures are not a fault of the new binary. The preflight can
run out of time. The running version can also fail the same checks, for example
when the server answers 503 or the rule file has an error. The bridge then logs
`update_deferred` with the reason `preflight`, and the next check tries again.

Each rollback puts the version on the skip list in `update.json`, in the config
directory. The bridge never installs a skipped version on its own. A version
that a rollback started never hands over again. When it is not healthy either,
it logs `update_rollback_skipped` and keeps running.

### Recovery after a crash

A bridge can die between the handover and its health check. The handover file
then stays in the config directory, and the workers go on without a parent. The
next `loupe bridge run` on the same rule file reads that file, takes over the
workers that still run, and logs `update_recovered`. It follows each worker by
its pid, because the worker is no longer its child. When the bridge that died
ran another version, the start puts that version on the skip list and logs
`update_rolled_back` with the reason `crash`. So a supervisor that restarts the
bridge does not start the same crash again. A handover file that the
bridge cannot read moves to `handover-<hash>.json.bad`, and the bridge logs
`update_recovery_failed`.

### Updating by hand

`loupe update` asks each running bridge to check and install at once. With no
bridge running, it downloads, verifies and installs the release itself. It
ignores the skip list and `autoUpdate`. It refuses to update a binary that
Homebrew installed, and it then asks no bridge. It prints
`brew upgrade loupe`, changes nothing, and exits with status 1. A bridge that
runs a Homebrew binary also refuses the request of an older CLI. See
[`cli/README.md`](../../cli/README.md#loupe-update).

### Log events

| Event | What happened |
|---|---|
| `update_check` | A check starts |
| `update_available` | A release waits, and `autoUpdate` is not `true` |
| `update_unavailable` | The running version is outside the range, and no release fits |
| `update_blocked` | The bridge cannot write the directory of its binary |
| `update_download` | The bridge downloads a release |
| `update_verified` | The archive matches `checksums.txt` |
| `update_rejected` | The archive does not match, or holds no binary |
| `update_deferred` | The handover waits for the next check |
| `update_handover` | The bridge runs the new binary |
| `update_applied` | The new version is healthy |
| `update_installed` | The new binary replaced the installed one |
| `update_unhealthy` | The new version goes back to the old one |
| `update_rollback_deferred` | The rollback waits, because run reports are still in flight |
| `update_rolled_back` | A version went on the skip list |
| `update_recovered` | A start took over the handover of a bridge that died |
| `auto_update_migrated` | A handover from an older CLI added `autoUpdate: true` |
| `openrouter_prices_loaded` | The bridge loaded the OpenRouter price list. The event carries the model count |
| `openrouter_prices_failed` | A fetch of the price list failed, once for each run of failures. Level `WARN` |

[Output](../../cli/README.md#output) in `cli/README.md` lists every event with
its fields, the failure events included.

## Pause, stop and resume

The server can pause a bridge, stop or resume one of its runs, run a failed
command run again, and ask for the usage of an interactive run. Each heartbeat
tells the server that the bridge takes these commands, with
`capabilities: ["commands", "rerun-command", "session-usage"]`.
[Pause and commands](../reference/bridge-heartbeat.md#pause-and-commands)
describes the protocol.

A paused bridge starts no queued run, and its running workers go on. It keeps
the pause in `pause.json` in the config directory, so a restart keeps it.

A stop of a queued run closes the run as `stopped`. A stop of a live worker
reports `stopping`, then sends SIGINT, SIGTERM and SIGKILL to the process group
of the worker. The flags `bridge.stop_sigterm_after_ms` and
`bridge.stop_sigkill_after_ms` set the waits between the signals. The run then
reports `stopped`, and the bridge never resumes it. A stop holds nothing, so
the next request of the card can start a worker. Against an older server with no
[held list](#held-cards), the bridge holds the card on a stop. It then starts
no worker for the card until a resume or a rerun of the card ends the hold.

A stop reaches the process group of the worker only. Work that the worker
started in another process tree keeps running, such as a PHPUnit run inside a
Docker container.

A resume continues the session of a run that ended, with a fixed prompt. The
bridge refuses it when it cannot read the card, when this machine holds no
transcript of the session, or when the work map no longer runs workers of the
kind of the run. [Resume](#resume) says which account a resume uses. Loupe
also sends a resume on its own when an
[inbox](../using/inbox.md) ask of the session closes. That resume names the
cause `ask-closed`, and its prompt tells the agent to read the answers. A resume
that the owner starts from an inbox item names the cause `inbox-action`. It uses
the prompt of a person's resume, plus a line that names the inbox item and the
action. The command carries the keys `inboxActionId`, `inboxItemId` and
`inboxItemNumber`. A work request that an inbox action sent carries the same
three keys. So does the first request of a rule after the owner retried its
pause from the inbox. The keys hold ids only. The line tells the agent to call
`inbox_get` with the item id and to read the note of that action. The prompt
of a work request also tells the agent to read the report of each stopped run
of the item with `worker_run_get`, because a new agent has no transcript of
that run. The bridge adds these lines only when the item id and the action id
are both set.

A rerun runs the command of a [command run](#command-action) again, after the
run ended as `failed`, `timed-out` or `lost`. The person selects **Run again**
on the run. The bridge queues the command as a new run that continues the
failed run. It refuses the rerun when the work map no longer runs a command of
the kind of the run, and when the card has a run that is open on this bridge.
It also refuses a command that reads a value the run lacks, such as the work
request id of a run from before the work map. A rerun or a resume carries the
context of the run's work request, so the command and the `before` command fill
the pull request and the document that the first run had. A bridge that does not report the
`rerun-command` capability gets no rerun, and the web UI disables the control.

A usage request asks for the token usage of one interactive run, which has no
worker process. The bridge reads the usage of the run's window from the
transcript of the session on this machine, subagents included, and
sends it as an estimate. It refuses the request when this machine holds no
transcript of the session. Only a bridge that reports the `session-usage`
capability gets a usage request.
[Pause and commands](../../cli/README.md#pause-and-commands) in `cli/README.md`
gives every field and log event.

## Before command

A worker entry can set `before`, a command that runs ahead of the worker. The
command makes or refreshes the folder the worker runs in, and prints that
folder as the last line of its output. The run reports the state `preparing`
while the command runs. It holds the run's worker slot and its card, so no
other run of the card starts first.

The run fails, and the worker does not start, when the command exits with a code
that is not 0, runs past its timeout, or prints a path that is not an existing
directory. It also fails when the bridge cannot read the command's output. The command runs again before each resume. A resumed conversation
starts in the folder its transcript records. It starts in the printed folder
only when the session has no transcript on this machine, or when the recorded
folder is gone. A recorded folder that the bridge cannot read fails the resume.
[The before command](../../cli/README.md#the-before-command) in
`cli/README.md` gives the fields, the timeouts and the folder contract.

## Command action

An entry with `action: command` runs a command for the card, and starts no
agent. Use it for a step that needs no judgement, such as the teardown of a
card's worktree when the card reaches `done`:

```yaml
work:
  teardown:
    action: command
    run: ["bin/teardown.sh", "{cardNumber}"]
```

`run` is an argv list, and no shell reads it. Each element takes the
placeholders that a prompt takes. The command runs in the project's `dir`, with
the bridge's environment. `timeout` defaults to `10m`, and the check refuses
more than `60m`. The check refuses `prompt`, `account`, `model`,
`permissions`, `variants`, `metrics`, `workerPool` and `before` on a command
entry. A worker or interactive entry cannot set `params` or `description`.

### Command parameters

A command entry can set `description`, and `params`. `run` names a parameter
as a placeholder, such as `{out}`:

```yaml
work:
  design-export:
    action: command
    description: Export the design system for Claude Design
    params:
      out: { pattern: "var/[a-z-]+", description: The export folder }
    run: ["bin/console", "app:design-system:export", "{out}"]
```

Each parameter needs a `pattern` in RE2 syntax, with no lookahead and no
backreference. The bridge wraps it as `^(?:pattern)$`, so a value must match
whole. The value fills one element of `run` and never splits. The check refuses
a parameter with no pattern, a pattern that does not compile, and a name that is
a built-in placeholder. A pattern is only as strict as the operator writes it,
so write the narrowest pattern that fits. A loose pattern lets a value such as
`--exec=...` through.

The heartbeat lists each command entry with its parameters, and each worktree of
the followed projects. The bridge always reports the capability `run-command`.

### Running an entry from the inbox

When the owner confirms a "Run a command" action in the [inbox](../using/inbox.md#running-a-command),
Loupe opens a work request for this bridge only. The request names the entry, a
branch, a value for each parameter, and the `run` list that the owner confirmed. The bridge checks the request again
against its own `rules.yaml`, and settles it as refused, with a reason code,
when a check fails:

- `entry-missing`: the work map has no command entry of that name.
- `entry-changed`: the `run` list of the entry is not the one the owner
  confirmed.
- `param-invalid`: a value is missing, undeclared, or does not match the whole
  pattern.
- `worktree-missing`: `git worktree list` shows no folder with that branch.
- `placeholder-missing`: `run` uses a placeholder with no value.

The bridge runs the command in the folder of the branch. The run reports
`queued`, `running`, then `succeeded` or `failed`, with the end of the output,
as [Command runs](../reference/worker-runs.md#command-runs) says. The inbox
shows that state and output. A success closes the to-do or resumes the waiting
worker. A failure keeps the item open, with **Run again**.

While the command runs, the bridge merges its standard output and standard error
into one stream. About once a second it sends the new bytes to
`PUT /api/projects/{handle}/worker-runs/{runId}/output`, with the byte `offset`
of the first byte. Loupe stores the text only when `offset` equals the number of
bytes it holds, and answers with that number as `length`. The bridge sends again
from `length` after a lost answer or a refused offset. The last piece goes out
before the report that ends the run. A stream that fails never fails the run.
[Appending the output of a run](../reference/worker-runs.md#appending-the-output-of-a-run)
has the request and the 1 MiB limit. The inbox page reads the stored log, so it
shows the output as it arrives and keeps it after the run.

Update the CLI before you add `params` to `rules.yaml`. A CLI from before this
change refuses the key, and the whole file fails to load. The output can
contain secrets that the command prints.

### Accept entries from Loupe

`acceptEntries: true` at the top of `rules.yaml` lets Loupe add a command entry
to the file, after the owner confirms. The bridge then reports the capability
`add-entry`. The key is off when it is absent.

You must set the key by hand. The bridge never sets it.

A compromised server could then add and run any command entry. It could run any
program as the bridge user. Turn the key on only when you accept that risk.

When the owner chooses **Add to rules.yaml**, the server sends the bridge a work
request with the capability `add-entry`. The `prompt` field holds the entry
text: one YAML mapping with one key, the entry name. The bridge starts no agent
and takes no worker slot. It checks that the file still sets
`acceptEntries: true`, adds the entry under `work`, and checks the new file as a
normal load does. It writes the file only when the checks pass. It then reloads, as
`loupe bridge reload` does, and settles the request as `done` only when the
reload runs the entry. An add waits for a reload that already runs.

The bridge rewrites the whole file from its parsed YAML tree. Comments and
layout can change. Keep a copy of the file if the layout matters.

The bridge refuses a request with one of these reason codes, and leaves the
file as it was:

- `entry-invalid`: the text does not parse, holds more or less than one key,
  has a body that is no mapping, has an action other than `command`, or the
  new file fails its checks.
- `entry-exists`: the `work` map holds an entry with this name already.
- `not-accepted`: the file does not set `acceptEntries: true`.
- `write-failed`: the bridge could not read or write the file.
- `file-changed`: the file changed while the bridge added the entry. The
  bridge keeps the other edit and writes nothing. Try the add again.
- `reload-failed`: the bridge wrote the entry, and the reload after it failed.
  The bridge then puts the old file back, so the entry never shows as added
  while the bridge does not run it. When the file changed again after the
  write, the bridge keeps it, and the log line `entry_reload_failed` says so.

The bridge acts only on a request that names its own bridge id.

Update the CLI first. A CLI from before this change refuses `params` and
`acceptEntries` as unknown keys, and the whole file fails to load.

A command takes no worker slot. It holds its card, so it waits for a worker of
the card that runs, and a worker that arrives later waits for it. Commands on
different cards run at the same time. The run reports `queued`, then `running`
with no session. It ends as `succeeded` on exit code 0, and as `failed`
otherwise. A timeout, a kill, or a command that never starts gives the exit
code `-1`. The output of a failed run starts with the reason, and the end of the
command's output follows. Each report carries `"kind": "command"`, as
[Reporting a run state](../reference/worker-runs.md#reporting-a-run-state)
says.

The bridge does not retry a failed command. A person can select **Run again**,
as [Pause, stop and resume](#pause-stop-and-resume) says. A command that runs
when the bridge updates itself is handed over, and the new image waits for it.
[The command action](../../cli/README.md#the-command-action) in
`cli/README.md` gives every field and the queue rules.

## Work requests

A work request is one piece of work on one subject, such as a card, that the
server offers to the bridges. One bridge claims it, runs it, and posts the result. The top-level
`work:` map of `rules.yaml` says what the bridge runs for each kind of request.
[Workflows](../using/workflows.md#kinds-of-work) lists the kinds that the
shipped templates ask for.

```yaml
work:
  implement:
    prompt: Implement card {cardNumber} in project {project}.
  product-design:
    action: interactive
    prompt: /loupe:product-design {cardNumber}
  review:
    model: opus
    prompt: Run the loupe-stage-review skill for card {cardNumber} in project {project}.
  teardown:
    action: command
    run: ["bin/teardown.sh", "{cardNumber}"]
```

The key is the kind of work. Lifecycle asks for the kind `sync-epic` when a
child waits for a blocker outside its epic and the project has no GitHub App
installation. Its entry merges `origin/<default branch>` into a detached
`origin/epic/<n>`, pushes without force, and stops blocked on a conflict. It
also stops blocked when the merged pull requests of the blockers live in
another repository than `origin`. Loupe asks for this work only when that code
lives in one repository. The entry sets nothing else. When the run ends done,
Loupe records on the epic the blockers that were done when the work opened.

A worker entry takes `prompt`, `account`,
`model`, `permissions`, `before`, `workerPool`, `variants` and `metrics`. A command entry takes
`run` and `timeout`. The rule check
refuses a field that the action does not use.

A work request is about a subject. The `subject` key of an entry names the
subject type that the entry runs, and it is `card` when you leave it out. An
entry skips a request about another subject type. An entry about a subject
that is no card cannot use `{cardId}` or `{cardNumber}`, and cannot open an
interactive session.

```yaml
work:
  analysis:
    subject: analysis
    model: sonnet
    prompt: Run the loupe-analysis skill for analysis {subjectId} of project {project}.
```

A bridge with `appPrompts: true` runs an analysis with no such entry, because
the request carries the prompt that Loupe ships. The `analysis` entry above
runs the analyses that the owner starts on the
[Reports](../using/analytics.md#reports) tab. The `loupe-analysis` skill of the
Loupe plugin does the work. A work request can name a model and an effort. A
request model replaces the `model` of the entry. A request effort reaches
claude as `--effort`. A work entry has no effort of its own. A request model
also skips the [experiment](#experiments) draw of an entry with variants, so
the run joins no experiment. An analysis always names its model and its
effort.

Both shipped workflow templates
request `teardown` each time a card reaches a terminal column. A `teardown`
request that no bridge takes expires after the work timeout, and the card does
not pause.

The `review` kind runs the `loupe-stage-review` skill of the Loupe plugin. The
worker reads the change of the pull request at its head commit, and sends its
findings with the `agent_review_submit` tool. It changes no file. Give the entry
its own `model`, because the reviewer should not be the model that wrote the
code. Use a `workerPool` apart from the `implement` entry, so a review does not
wait for a long implementation run. The entry needs a worktree only for the git
objects, so a `before` command is optional. A bridge with `appPrompts` alone runs
no review: the review rules belong to the Lifecycle template, which carries no
app prompt. When a `fix` request starts for an agent review, the `fix` entry runs
the `loupe-stage-fix-round` skill, which reads the findings from `card_get`.

The `design-review` kind runs the `loupe-stage-design-review` skill. The
worker reads a draft product design or a draft tech design, with its product
document, the code and the prototype, and sends its findings with the
`document_review_submit` tool. It changes no file and no document. Give the
entry its own `model`, and a `workerPool` apart from the design entries, as for
`review`. The entry needs no worktree, so it has no `before` command. A bridge
with no `design-review` entry takes no design review: after the work timeout
the card pauses with "no bridge took the work", and the draft stays hidden
until the owner publishes it.

```yaml
work:
  design-review:
    model: opus
    prompt: |
      Use the loupe-stage-design-review skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}).
      Loupe instance https://loupe.ac.
```

When a `product-design-revise` or `tech-design-revise` request starts for an
agent review, the same `loupe-stage-fix-round` skill answers the findings. It
revises the draft, and records one answer for each finding with the
`document_review_answer` tool. The request carries the reason `agent-review`.
Put the line `Fix reason: {reason}.` in the prompt of both entries, so the
worker can choose the agent review path. See
[Agent review of a design](../using/workflows.md#agent-review-of-a-design).

The file needs `work:`, or `appPrompts: true`. The bridge reports the
`work-requests` capability when the map has an entry or `appPrompts` is on,
and `interactive` too when an entry opens an interactive session. An entry for
the kind `session` makes it report `session` as well. It also
reports `subject-<type>` for each subject type other than `card` that an entry
names, such as `subject-analysis`. With `appPrompts: true`, it reports
`app-prompts` too, unless the account of `defaults.account` is off. A request
that needs a capability reaches only a bridge that reports it. A request that names a `subject-` capability and carries an app
prompt also reaches a bridge that reports `app-prompts`.

A rule that Loupe ships can send a prompt with its request. Set
`appPrompts: true` at the top of `rules.yaml` to run that prompt for a kind that
`work:` does not map. The prompt runs as a worker in the `default` pool, with
the `defaults` of the file. A model or an effort in the request replaces the
default model and sets the effort. An entry under `work:` always wins, unless
it names another subject type than the request. A bridge without
the key skips such a request, and the request expires after the work timeout.

The bridge finds the project of a request in `projects` through the project
id. A project rename marks the work of that project dead until you fix the file
and run `loupe bridge reload`.

The bridge claims a request when the run gets its worker slot, just before the
`before` command. When another bridge won the claim, the bridge runs nothing
and reports no run. Each heartbeat renews the lease of each claim the bridge
holds. The bridge stops the run, and posts no result, when the server says the
claim is lost, or when the request is cancelled or expires. Otherwise the run
posts `done` when it finishes, and `refused` with a reason when it does not. A
work run resumes only when the workflow retries it with a session to resume. Its
log lines name the rule `work:<kind>`.

[The work map](../../cli/README.md#the-work-map) in `cli/README.md` gives every
field, the placeholders and the result of each outcome.
[Work requests](../reference/bridge-heartbeat.md#work-requests) gives the
protocol.

## Hooks

A hook package runs a local program when the bridge starts, stops, gets busy or
goes idle. The `hooks:` list of `rules.yaml` names each package and the commit
it runs. [Bridge hooks](bridge-hooks.md) covers the events, the manifest, the
trust model and the Amphetamine package. These commands manage the list:

| Command | What it does |
|---|---|
| `loupe bridge hooks install <owner>/<repo>[/<path>]@<ref>` | Installs or updates a package from GitHub at one commit |
| `loupe bridge hooks list` | Shows the installed packages and their settings |
| `loupe bridge hooks remove <owner>/<repo>[/<path>]` | Removes a package from the rule file |
| `loupe bridge hooks set <owner>/<repo>[/<path>] <name>=<value>` | Sets one setting of a package |
| `loupe bridge hooks run <owner>/<repo>[/<path>] <event>` | Runs one hook now, from your terminal |

Run `loupe bridge reload` after `install`, `remove` or `set`.

## Events endpoint

`GET /api/events` returns what a client needs to follow the events of every
project the token's user owns:

```json
{
  "hubUrl": "https://mercure.example.com/.well-known/mercure",
  "jwt": "<subscriber JWT>",
  "topic": "https://loupe.example.com/users/0192f3a1-0000-7d3e-8f10-a2b3c4d5e6f7/events",
  "projects": [
    {"id": "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7", "slug": "my-app", "name": "My App"}
  ],
  "flags": {
    "inbox.enabled": false,
    "bridge.heartbeat_interval_seconds": 60,
    "bridge.stop_sigterm_after_ms": 7500,
    "bridge.stop_sigkill_after_ms": 2500,
    "bridge.host_sampling_enabled": false,
    "bridge.host_sample_interval_seconds": 60
  },
  "cliRange": "^1.0",
  "head": 4812
}
```

The request names the bridge in an `X-Loupe-Bridge` header. The server answers
`426` with an `error` message when the header is missing or is not a UUID, when
the user has no bridge with that id, or when the last heartbeat of the bridge
does not list the `work-requests` capability. The bridge sends a heartbeat
before its first call, and it stops on a `426`. The replay route below applies
the same check. Only the types `bridge.work_request`, `bridge.command`,
`board.card_held`, `board.card_released` and `project.renamed` reach the hub
and the replay. Every other event stays in the activity feed alone.

`head` is the highest outbox sequence of the projects that the user owns, as a
JSON integer. It is `0` when those projects hold no event. A bridge with no
saved cursor can start from it, and replay nothing older.

`cliRange` is the range of CLI versions that the server supports. `loupe update`
reads it when no bridge runs. A running bridge reads the same range from the
heartbeat reply.

`flags` holds the feature flags a bridge reads. The server lists a flag here
only when its code names the flag, so no other flag reaches a token holder. A
value is a boolean or an integer, as the flag's type says. Today the map holds
six flags:

| Flag | Type | Value |
|---|---|---|
| `inbox.enabled` | boolean | `false` on an instance that holds no row for it |
| `bridge.heartbeat_interval_seconds` | integer | the seconds between two heartbeats, 60 on an instance that holds no row for it. A stored value below 10 reads as 60 |
| `bridge.stop_sigterm_after_ms` | integer | the milliseconds from SIGINT to SIGTERM when the bridge stops a run, 7500 on an instance that holds no row for it. A stored value below 100 reads as 7500 |
| `bridge.stop_sigkill_after_ms` | integer | the milliseconds from SIGTERM to SIGKILL when the bridge stops a run, 2500 on an instance that holds no row for it. A stored value below 100 reads as 2500 |
| `bridge.host_sampling_enabled` | boolean | whether the bridge takes [host samples](#host-samples), `false` on an instance that holds no row for it |
| `bridge.host_sample_interval_seconds` | integer | the seconds between two host samples, 60 on an instance that holds no row for it. A stored value below 5 reads as 60 |

The bridge reads the map at start, at each reconnect and at each
`loupe bridge reload`. A flag change therefore reaches a running bridge at its
next reconnect or reload.

`topic` is the user's own topic. The server publishes each event of a project on
the project's topic and on its owner's topic. The JWT expires after an hour, and
its `subscribe` claim lists the user's topic alone. A user with no project gets
an empty list and the same topic. The endpoint answers `404` when push is
switched off on the instance.

The hub URL and the JWT have the same size however many projects a user owns.
`projects` lists the projects at the moment of the call. A project created later
publishes on the same topic, so a subscriber receives its events with no new
call. Each event names its project in `projectId`.

Events reach the project owner's topic only. A person who is not the owner
receives no event there.

This endpoint replaced `GET /api/projects/{handle}/stream`. A CLI binary built
before the change calls the old route, gets `404`, and must be rebuilt.

### Replay

`GET /api/events/replay?after=<sequence>` returns the events that a client
missed while it was stopped or reconnecting. It reads the outbox, which holds
every event, so it does not depend on the history of the hub.

```json
{
  "events": [
    {"id": "4811", "type": "bridge.work_request", "data": "{\"projectId\":\"0192f3a1-...\"}"},
    {"id": "4812", "type": "bridge.command", "data": "{\"projectId\":\"0192f3a1-...\"}"}
  ],
  "hasMore": false
}
```

1. `after` is a required integer, `0` or higher. A missing, non-integer or negative value answers `400`.
2. `id` is a string, the same text that the hub sends as the SSE id. It holds the outbox sequence, so parse it as a 64-bit integer.
3. `data` is the stored payload string, byte for byte what the hub sends.
4. The list holds the events of the projects that the user owns, in sequence order.
5. The route also returns events that the hub has not published yet.

A page holds at most 200 events with a sequence above `after`. `hasMore` is
`true` when more such events exist. Read the next page with the highest `id` of
the page as `after`.

The server takes a sequence at insert, and the row becomes visible at commit.
So a row can become visible after a higher row was already delivered. To cover
it, each page also repeats some events at or below `after`. The anchor is the
event with the highest sequence at or below `after`, in any project. The page
repeats the user's events at or below `after` that were created at most two
minutes before the anchor, at most 200 of them. When no anchor exists, the page
repeats nothing. A client must therefore drop an event whose `id` it already
handled.

The route needs an agent-scoped token, and answers `404` when push is switched
off. It allows 60 calls per minute per token.

#### How the bridge uses it

The bridge keeps a cursor in `cursor-<hash>.json` in its config directory,
beside the handover file. The file holds the highest sequence the bridge
handled, the floor, and the ids of the last events it handled. The bridge
writes it after each event it handles.

On every connect, the bridge reads the pages after its cursor before it reads
the stream. It drops an event whose `id` it handled already, and routes the
others as the hub sends them. The hub can send the events of a catch-up again
after it, so the bridge remembers the ids of the last 20000 events that
catch-ups read, and none of them runs twice. The server can repeat an event at
or below `after`, so the bridge never drops an event only because its `id` is
below the cursor. When a page fails, for example with `429`, the bridge logs
`catch_up_failed` and reads the stream live. The saved cursor then stays at the
page that failed, also across a restart, while live events run. The next
connect reads again from there, and a catch-up that reads to the last page
moves the cursor to the highest id it read. While the gap is open, the file
keeps the id of every event the bridge handled, so none of them runs twice.

A bridge with no cursor file starts from `head`, which also becomes its floor.
The bridge never runs a replayed event at or below the floor, so a first start
does not run the events that happened before it. A server that sends no `head`
gives the bridge no cursor, and the bridge then catches up nothing.

A replayed work request can be old. The bridge claims it before it runs, and the
server refuses the claim of a request that ended, so a stale request runs
nothing.

### Held cards

A person or an agent makes a card unmanaged, and Loupe then holds the card. A
stop of a run holds nothing. Loupe writes a `board.card_held` event when a
person selects **Make unmanaged** or an agent calls `card_hold`. It writes a
`board.card_released` event when a person selects **Manage again** or an agent
calls `card_release`. The two events have the same payload.

```json
{
  "type": "board.card_held",
  "subject": { "type": "card", "id": "01a0a1b2-0000-7c3d-8e4f-5a6b7c8d9e0f" },
  "projectId": "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7",
  "actor": "human"
}
```

| Field | Meaning |
|---|---|
| `type` | `board.card_held` or `board.card_released` |
| `subject.type` | always `card` |
| `subject.id` | the card whose agents the person or the agent paused or let run |
| `projectId` | the project of the card |
| `actor` | `human` for a person on the card page, `agent` for an MCP call |

The bridge keeps the hold, starts no worker on a held card, and starts the
queued runs of the card on the release. A worker that runs when the hold starts
goes on, and so does the request it runs for. Loupe cancels each open work
request of the card when the hold starts. It offers no request of a held card
to a bridge, and it refuses a claim of one with `card_held`. It does not
reopen a lapsed claim of a held card until the hold ends.

`GET /api/card-holds` returns the held cards of every project that the user
owns, in the order of the holds.

```json
{
  "holds": [
    {"projectId": "0192f3a1-...", "cardId": "01a0a1b2-..."}
  ]
}
```

The list is empty when no card is held. The route needs an agent-scoped token,
and allows 60 calls per minute per token. An older server answers `404`.

## Interactive action

An entry with `action: interactive` opens an interactive Claude Code or Codex
session in a terminal window, on the bridge's machine, as its account says. It is for Product design, so the
owner does not type `/loupe:product-design` by hand. An entry without `action`
is a worker entry.

```yaml
launch:
  command: ["open", "-a", "Terminal", "{script}"]

work:
  product-design:
    action: interactive
    prompt: /loupe:product-design {cardNumber}
```

An interactive entry takes `prompt`, `account`, `model` and `permissions`. The check
refuses `before`, `variants`, `metrics` and `workerPool` on it. It also refuses the action
on Windows, because the launch script is a POSIX shell script. The action works
on macOS and Linux.

The session gets `--permission-mode` only when the entry sets `permissions`.
The account's `permissionMode`, `defaults.permissions` and the bridge flag do
not fill it. So a worker default such as the `full` level never reaches a
session that a person drives. The model follows the order of
[Model and permissions](#model-and-permissions). The session gets the rendered
prompt only, with no result footer and no inbox line.

On a `codex` account the script runs `codex` with the profile, the model and
the sandbox flags of the entry's `permissions` level, and the prompt. It sets
`LOUPE_SESSION_ID` to the session id of the run, so the session can open its
run on the card. Codex picks its own thread id. The bridge keeps the folder and
the time of the launch, and later finds the Codex session file that started in
that folder after the launch. It needs that file to collect the usage of the
session. A session that has not started a thread yet has no usage to collect.
Two launches in one folder match their sessions in launch order. A launch that
never starts Codex can shift the match of a later launch in that folder for up
to a day, so the later run can report the usage of the wrong session.

The top-level `launch` block of `rules.yaml` names the command that opens the
terminal. It lives in `rules.yaml` because that file belongs to one machine, so
each machine sets its own launcher. A file with an interactive entry and no
`launch.command` fails to load. The bridge then refuses to start, and
`loupe bridge reload` keeps the old file.

| Field | Required | Purpose |
|---|---|---|
| `command` | with an interactive entry | The argv list of the launcher. No shell reads it. One element must hold `{script}` |
| `timeout` | no | How long the bridge waits for the launcher, such as `10s`. Defaults to 10 seconds |

`command` takes the placeholders `{script}`, `{dir}`, `{sessionId}`,
`{cardNumber}` and `{project}`.

For each launch, the bridge writes a script to
`<temp dir>/loupe-sessions/<sessionId>.sh`, with mode `0700`. The script deletes
itself, changes to the project's `dir`, and runs the harness. On a
`claude-code` account it runs `claude --session-id <sessionId> -- '<prompt>'`.
A terminal app can start with a short `PATH`. So the bridge finds the program at
the launch, on the `PATH` of the account, and writes the absolute path into the
script. The launch fails when the program is missing. An env file can put
`claude` on that `PATH`, so `claude` need not be on the `PATH` of the bridge.
A `claude-code` account without `claude` on its `PATH` is turned off by its
account check, and the other accounts run. The bridge never needs `claude` on
its own `PATH`. At start, it also deletes scripts older than one day.

The bridge runs the launcher with no shell, and never kills it. An exit with
code 0 within the timeout is a launch. A launcher that still runs at the timeout
is a launch too, and the bridge leaves it running, because some Linux terminals
wait until the window closes. A non-zero exit or an exec error is a failed
launch.

A launch uses no worker slot, and does not wait for a worker on the same card.
A paused bridge leaves an interactive request to another bridge.

The bridge reports each launch to
`PUT /api/projects/{handle}/interactive-runs/{sessionId}`. The report names the
card as the subject, with `subjectType` `card`, `subjectId` and `cardNumber`. An
interactive run is about a card, so the server answers 422 to any other subject
type. A good launch opens an interactive run in the state `running`, with the
work kind and the bridge id. The report can also carry `harness`, `account`,
`model` and `harnessSessionId`, with the rules of the
[run state report](../reference/worker-runs.md#reporting-a-run-state). A retry
of the launch report fills these fields on the run it finds.
The session's `/loupe:product-design` skill calls `card_run_open` with the same
session id, and takes over that run. So the
[Runs tab](../using/worker-runs.md#interactive-sessions) of the Activity page
shows one row. The run ends as `closed` when the skill calls `card_run_close`,
when the card moves, or when a person closes it on that page. A failed launch
records a `not-started` run, with the exit code and the launcher output as its
reason.

The prompt must close its own run, and the product design skill does. A prompt
that calls neither `card_run_open` nor `card_run_close` leaves the run open,
until the card moves or a person closes it.

An interactive run has no worker process, so the bridge sees none of its usage
as it runs. When the server asks, the bridge reads the usage of the run's
window from the local transcript and sends it as an estimate. See
[Pause, stop and resume](#pause-stop-and-resume).

### Session entry

The action **Start an interactive session** on an inbox item opens a work
request of the kind `session`. The request is about the card of the item. A
`session` entry in `work:` tells the bridge what to open.

```yaml
launch:
  command: ["open", "-a", "Terminal", "{script}"]

work:
  session:
    action: interactive
    prompt: Work with the owner on card {cardNumber} in project {project}.
```

The entry must use `action: interactive`, and the file needs the `launch`
block. The bridge reports the capability `session` when the map has the entry.
The request needs that capability, and it names no bridge. The first bridge
that claims the request opens the terminal. So put the `session` entry on the
one machine where you want the terminal, as for
[Product design](#interactive-action).

The session always starts new. It never resumes the session of an earlier run
of the card. The prompt of the session names the inbox item by its number and
its id. It tells the agent to read your note with the `inbox_get` tool, and the
earlier report of the run with `worker_run_get`. The request holds no note text.

When the owner started the session from a suggestion with a prompt, the bridge
appends that text as the last part of the first message. It comes after the
prompt of the `session` entry and the inbox line. The text is what the owner
left in the field. An older bridge ignores it. The agent can still read the
suggestion with `inbox_get`.

A `session` request that no bridge claims expires after 2 minutes, and the
card does not pause. Loupe disables the action while no online bridge of the
project owner reports `session`.

## Columns endpoint

`GET /api/projects/{handle}/board/columns` returns the columns of one board, so
the bridge can resolve each project of its rule file at start. The handle is a
project id or a project slug. A project name does not resolve, and a handle
cannot hold a slash. The token's user must own the project.

```json
{
  "project": { "id": "01a0…", "slug": "my-app" },
  "columns": [
    { "slug": "backlog", "label": "Backlog", "terminal": false, "default": true, "backlog": true },
    { "slug": "done", "label": "Done", "terminal": true, "default": false, "backlog": false }
  ]
}
```

The columns come in board order. The board does not draw Backlog as a column,
but the list keeps it. `default` and `backlog` are
both true on that row alone. A seeded label is translated, and a label a person
typed comes back as typed. `project.slug` is the project's slug.

| Status | Body | When |
|---|---|---|
| 200 | the object above | the user owns the project |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token carries another scope, such as `site-review` |
| 404 | `{"error":"project_not_found"}` | the user has no project with that handle, and another user's project counts as none |
| 429 | | more than 60 reads in one minute from one token |

## Card endpoint

`GET /api/projects/{handle}/board/cards/{cardId}` returns the column a card is
in now, and whether a person paused the agents on it. The bridge calls it before
a person's resume, and it refuses the resume when the read fails. The handle follows the same rules as the
columns endpoint, and `cardId` is the card's uuid.

```json
{ "cardId": "01a0a1b2-0000-7c3d-8e4f-5a6b7c8d9e0f", "number": 42, "column": "implementation", "held": false }
```

| Field | Meaning |
|---|---|
| `cardId` | the card the path names |
| `number` | the short number the card shows |
| `column` | the slug of the card's column |
| `held` | `true` when a person paused the agents on the card. A move of the card to another column by a person releases the hold. An older bridge ignores the key |

| Status | Body | When |
|---|---|---|
| 200 | the object above | the user owns the project and the project holds the card |
| 401 | | the request carries no token |
| 403 | `{"error":"insufficient_scope"}` | the token carries another scope, such as `site-review` |
| 404 | `{"error":"project_not_found"}` | the user has no project with that handle, and another user's project counts as none |
| 404 | `{"error":"card_not_found"}` | the project holds no card with that id, or `cardId` is not a uuid. A card of another project counts as none |
| 429 | | more than 60 reads in one minute from one token, counted together with the columns endpoint |
