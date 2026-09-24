---
title: "Bridge hooks"
description: "Run a local program when the command-line bridge starts, stops, gets busy or goes idle. Preview, unreleased."
---

A hook package is a directory of a GitHub repository that holds a
`loupe-hook.yaml` manifest. The [command-line bridge](cli-bridge.md) runs the
commands of that manifest on four bridge events. A hook can keep a Mac awake
while a worker runs, or send a message when the bridge stops.

A hook only observes the bridge. It cannot block, delay or change a worker, and
its exit code changes nothing in the bridge. A hook that fails writes a log
line and a row on the Rules page. The bridge keeps running.

## The events

| Event | When the bridge runs it |
|---|---|
| `start` | once, after the bridge takes its lock and opens its control socket, and before it connects to the event stream |
| `stop` | once, as the last step of a shutdown, after every worker has ended |
| `busy` | when the bridge goes from idle to busy |
| `idle` | when the bridge goes from busy to idle |

The bridge is busy while a worker runs, while an ask check before a resume
runs, or while an event waits in its queue. A card that waits for a person at
its `maxChain` cap does not make the bridge busy. The bridge starts idle.

`stop` also runs when the bridge exits early after `start`, for example when
`GET /api/events` fails. A bridge that stops before `start`, such as on a bad
rule file, runs neither.

After a shutdown starts, the bridge fires no new `busy` and no new `idle`. A package that
cleans up on `idle` must therefore clean up on `stop` too.

The bridge runs one event at a time, in the order the events came. For each
event, it runs the hooks one at a time, in the order of the `hooks:` list. A
slow hook delays only the hooks that come after it. The bridge does not wait
for `start`, `busy` or `idle`. At a shutdown it waits for the `stop` hooks, and
the time limit of each hook bounds that wait.

A reload runs no hook. A package that a reload adds runs first on the next
event. It gets no `start`, and no `busy` when the bridge is already busy. A
package that a reload removes gets no `stop`. A package that a reload moves to
a new commit shows no last run until it runs again.

## The manifest

`loupe-hook.yaml` sits at the root of the package directory:

```yaml
name: notify
os: [darwin, linux]
timeout: 10
events:
  busy: [./notify, busy]
  idle: [./notify, idle]
  stop: [./notify, stop]
settings:
  channel:
    type: string
    default: general
    description: The channel that gets the message.
```

| Key | Rule |
|---|---|
| `name` | required. The name that `install` shows |
| `os` | optional. The systems the package runs on, as Go names such as `darwin` and `linux`. Empty means any system |
| `timeout` | optional. The time limit of one run, from 1 to 120 seconds. The default is 30 |
| `events` | required. Maps `start`, `stop`, `busy` or `idle` to a command. Name at least one event |
| `settings` | optional. The values an operator can set, each with a `type`, a `default` and a `description` |

A command is a list. Its first item is a program of the package, and it starts
with `./`. A command that leaves the package directory is an error. A setting
name starts with a lower-case letter and holds lower-case letters, digits and
`_`. A setting `type` is `bool` or `string`, and a `bool` takes `true` or
`false` only. A key or an event that this table does not name is an error.

## How a hook runs

The bridge runs the program directly, with no shell. The working directory is
the package directory, and stdin is empty. The hook gets the environment of the
bridge, plus these variables, which win over a variable of the same name:

| Variable | Value |
|---|---|
| `LOUPE_HOOK_EVENT` | `start`, `stop`, `busy` or `idle` |
| `LOUPE_HOOK_PACKAGE` | the package, as `owner/repo` or `owner/repo/path` |
| `LOUPE_BRIDGE_ID` | the id of the bridge |
| `LOUPE_HOOK_STATE_DIR` | the state directory of the package |
| `LOUPE_HOOK_SETTING_<NAME>` | one variable for each setting, with the name in upper case, such as `LOUPE_HOOK_SETTING_TAKEOVER`. A setting that the rule file does not set has its default |

The state directory is `hooks/state/<package>` in your config directory. The
bridge creates it before each run, readable by you alone. It stays when you
update or remove the package, so a package can keep a marker file there across
its runs.

A hook that runs past its time limit is killed. On macOS and Linux, the bridge
kills the whole process group of the hook, so a child process of the hook stops
too. On other systems it kills the hook process alone.

The bridge keeps the last 500 characters of the output, stdout and stderr
together. It logs one line for each run:

| Log line | When |
|---|---|
| `hook_ran` | the hook exited 0 |
| `hook_failed` | the hook exited non-zero, with `exit_code` and `output`, or it could not start, with `error`. Level `WARN` |
| `hook_timeout` | the bridge killed the hook at its time limit, with `timeout_seconds` and `output`. Level `WARN` |

The heartbeat carries the last run of each hook to Loupe. The Rules page shows
it, as [Bridge rule health](../using/board.md#bridge-rule-health) describes. The
row of a `stop` run never reaches Loupe, because the bridge has already closed
its send queue. The log line is the only record of that run. After an early
exit, the `start` row does not reach Loupe either.

## Trust

A hook runs with your rights and no sandbox. It can read your files, your
credentials and your `config.json`. Install a package only when you trust its
code. `install` prints the repository, the commit, the systems, the time limit,
each command and each setting before it asks you to confirm. Read that output.

## Install a package

```bash
loupe bridge hooks install <owner>/<repo>[/<path>]@<ref>
```

The package lives in a public GitHub repository. `<path>` is the package
directory inside the repository, and without it the package is the repository
root. `<ref>` is a tag, a branch or a commit sha. The command resolves the ref
to a commit, downloads the package at that commit, shows what it runs, and asks
`Install? [y/N]`. Use `--yes` to skip the question.

The bridge runs the commit, and never follows the ref later. A branch that
moves changes nothing until you install again. Name a commit sha to know what
you install.

The download holds at most 50 MiB of files and 10,000 entries. The package directory must
hold `loupe-hook.yaml`. A package whose `os` does not name this system does not
install. The files go to `hooks/packages/<package>/<commit>` in your config
directory.

An install of a package that is already installed updates it to the new
commit. It keeps each setting that the new manifest still takes, and names the
settings it drops.

The rule file must exist before you install. The command writes a `hooks:` list
into it and keeps the rest of the file, comments included:

```yaml
hooks:
  - package: ubermuda/loupe
    path: hooks/amphetamine
    ref: 4f0c2a1b9d8e7f6a5b4c3d2e1f0a9b8c7d6e5f4a
    sha: 4f0c2a1b9d8e7f6a5b4c3d2e1f0a9b8c7d6e5f4a
    settings:
      takeover: "true"
```

`ref` is the label you typed, and `sha` is the commit the bridge runs. Let the
commands write this list. A bridge built before hooks refuses a rule file that
holds a `hooks:` key.

At start, the bridge loads each package the list names. It refuses to start
when a package is not in the config directory or does not run on this system.
It also refuses a setting that does not fit the manifest. A rule file that you
copy to another machine therefore needs `hooks install` again there.

## The other commands

| Command | What it does |
|---|---|
| `loupe bridge hooks list` | Shows each package with its ref, its commit and its settings. A setting that the rule file does not set shows its default |
| `loupe bridge hooks remove <owner>/<repo>[/<path>]` | Removes the package from the rule file. Its state directory stays |
| `loupe bridge hooks set <owner>/<repo>[/<path>] <name>=<value>` | Sets one setting. The manifest must define it |
| `loupe bridge hooks run <owner>/<repo>[/<path>] <event>` | Runs one hook now, from your terminal, with the environment and the time limit that the bridge uses. It prints the output, and exits with status 1 when the hook fails |

Every `hooks` command takes `--rules <path>` to edit another rule file. A
package name ignores case, and `remove`, `set` and `run` take it without a ref.

`install`, `remove` and `set` change the rule file only. Run
`loupe bridge reload` to apply the change to a running bridge. A reload that
cannot load a package fails at the `hooks` stage and changes nothing.

`hooks run` needs no running bridge. It sends no row to Loupe. When you are not
logged in, `LOUPE_BRIDGE_ID` is empty.

## Amphetamine

The Amphetamine package keeps a Mac awake while the bridge is busy. It drives the
Amphetamine app through AppleScript, and it runs on macOS only.

The package is in this repository, and the repository has no tags. Install it
at the commit you trust:

```bash
loupe bridge hooks install ubermuda/loupe/hooks/amphetamine@<commit sha>
loupe bridge reload
```

| Event | What the hook does |
|---|---|
| `busy` | Starts an Amphetamine session with no end, and switches on closed-display mode. The display can still sleep |
| `idle` | Ends the session that the hook started |
| `stop` | Ends the session that the hook started |
| `start` | Ends a session that a crashed bridge left behind |

The hook writes a marker file in its state directory when it starts a session.
By default, `idle`, `stop` and `start` act only when that marker exists. They end an
active session only when it has no end. A timed session is yours, and the hook
leaves it on.

When a session of yours is already active at `busy`, the hook leaves it alone
and starts nothing. When your session ends, the Mac can sleep while a worker
runs.

### The takeover setting

```bash
loupe bridge hooks set ubermuda/loupe/hooks/amphetamine takeover=true
loupe bridge reload
```

With `takeover=true`, `busy` always starts a new session, and it replaces a
session of yours. `idle`, `stop` and `start` then end any active session,
whoever started it. The default is `false`.

### One-time setup on macOS

The first AppleScript call to Amphetamine makes macOS ask for the automation
permission. A bridge with no terminal, such as one under launchd, cannot answer
that prompt. Run the hook once from a terminal, and allow the prompt:

```bash
loupe bridge hooks run ubermuda/loupe/hooks/amphetamine busy
loupe bridge hooks run ubermuda/loupe/hooks/amphetamine idle
```

Amphetamine can also show a warning the first time closed-display mode goes on.
Dismiss it once.

When the Mac does not support closed-display mode, `busy` fails with exit code
3 and `closed-display mode is off`. The session stays on, and `idle` ends it as
usual.

### Limits

Amphetamine gives a session no id. The hook knows its own session only by the
marker file and by a session with no end. You can replace the session of the
hook with a session of your own that has no end. The hook then ends your
session at the next `idle` or `stop`.

When Amphetamine is not installed, each run that calls it fails and logs
`hook_failed`. `busy` always calls it. The bridge keeps running, and the Mac can
sleep.
