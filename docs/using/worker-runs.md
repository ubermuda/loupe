---
title: "Activity: Runs"
description: "The Runs tab of the Activity page, which shows what a command-line bridge told a project about the Claude Code workers it ran."
---

A [command-line bridge](../extending/cli-bridge.md) runs a Claude Code worker
for each board event one of its rules matches. Every worker the bridge reports
becomes one row on the **Runs** tab of the project's **Activity** page. An
interactive Claude Code session on a card gets a row too, as
[Interactive sessions](#interactive-sessions) says.

Open **Activity** in the project sidebar, or go to
`/projects/{project}/worker-runs`. Anyone who can view the project can read it.
The open runs also show on the [Workshop](workshop.md), under In motion.

The Activity page has three tabs. **Runs** lists the runs, and the rest of this
page describes it. **Events** lists the [project events](activity.md).
**Cost** charts what a finished card costs on average, as
[The cost of finished cards](#the-cost-of-finished-cards) says.

## What a row shows

| Column | Meaning |
|---|---|
| Work | the number of the card the worker was started for, then its title. A card that is gone, or a board that is off, shows the number alone. An interactive run adds the tag **Interactive session**, and a command run adds the tag **Command** |
| Outcome | the state of the run, from the list below. A run with a failure reason shows a help icon, and the reason shows when you hover over or focus the outcome |
| Work kind | the kind of the work request the run ran, such as `implement` or `fix`, or the name of an interactive session. A run from before the work map shows **None**. A long name is cut short, and the drawer shows it in full. A run that names a worker pool adds a tag with the pool, such as **quick pool** |
| Duration | how long the worker ran. A run that is still open shows how long it has run so far, and an open interactive run shows "running for" in front. A run with no start, or a run that closed with no reported end, shows nothing |
| Started | how long ago the worker started, on the bridge clock. Hover over it to see the exact time. A run that has not started shows when its first report arrived |

Select a row to open its drawer. The drawer holds the detail the row
leaves out: the bridge, the exit code, the run a resume continues, the
failure reason and the output.

A bridge runs its workers in named worker pools, and each report of a run names
its pool. The row and the drawer show the pool that the last report named. A
run from an older bridge, and an interactive run, name no pool and show none.

A work entry of a bridge can split its runs between the variants of an
experiment, and each card keeps the variant of its first run. The drawer of such
a run shows an **Experiment** line after the work kind, such as `impl-model / sonnet
(claude-sonnet-5-5)`. The line gives the experiment, the variant and the model
the variant asked for. When the entry no longer offers the variant of the card,
the card moves to a new variant. The line then adds a note, such as `, switched
from opus`. A run with no experiment shows no line.

A run that never started carries the reason instead of an exit code, such as a
missing `claude` binary, or a terminal launcher that failed.

A work entry of a bridge can run a command with no agent, such as a script that removes
the worktree of a finished card. See
[Command action](../extending/cli-bridge.md#command-action). Its run shows the
tag **Command** on this page, in the drawer, in the runs of a card and on the
Workshop. A command run has no session and no cost. It succeeds when the
command exits with code 0, and fails otherwise. The output of a failed run
starts with the reason, such as a timeout.

The list reads newest first, by when the first report of each run arrived, 20
runs to a page.

## The states of a run

| State | Meaning |
|---|---|
| **Queued** | the bridge accepted the event, and the run waits for its turn |
| **Replaced** | a newer event for the same card and rule took the place of this run |
| **Resumed** | the ask the session waited on closed, and the bridge resumes the session |
| **Skipped** | the session already read its answers, so the bridge did not resume it |
| **Preparing** | the bridge runs the `before` command of the rule, and the worker has not started |
| **Running** | the worker runs |
| **Stopping** | a person asked the bridge to stop the run, and the worker is still ending |
| **Stopped** | a person stopped the run, and the bridge does not resume it |
| **Waiting for a person** | a bridge from before the workflow engine capped the run. The bridge no longer sends this state |
| **Dropped** | the bridge stopped, the work of its project died, or a reload removed the work entry, before the run started |
| **Succeeded** | the worker exited with code 0, with a structured result. A command run succeeds on exit code 0 alone |
| **No result** | the worker exited with code 0, with no structured result |
| **Unfinished** | the worker said that its work still runs or remains |
| **Blocked** | the worker said that it cannot go on without a person |
| **Gave up** | the run did not finish, and the bridge already ran every resume the rule allows |
| **Failed** | the worker exited with any other code |
| **Never started** | the worker process never ran |
| **Timed out** | the bridge stopped sending its heartbeat while the run was open |
| **Lost** | the bridge reconnected, and it no longer holds the run |
| **Closed** | the interactive session ended, or its card moved to another column |

Queued, Resumed, Preparing, Running and Stopping are open states. A bridge that dies cannot close
its runs, so Loupe closes them. **Timed out** is a guess: a bridge can go quiet
and come back, and a later report from it replaces the guess. **Lost** is a
fact: the bridge came back without the run, so the run can no longer end. See
[the worker run API](../reference/worker-runs.md#timed-out-and-lost) for the
rules.

The structured result is the status and the summary that every worker prompt
asks for. A **No result** run exited cleanly but may have stopped before its
work was done, so read its output. A run from an older bridge carries no result
check, and its outcome comes from the exit code alone.

## Resumed runs

Loupe decides each resume, and the bridge resumes nothing on its own. A run that
says **Unfinished** refuses its work request, and the workflow retries the
request on the same session. When an [inbox](inbox.md) ask of a session closes,
Loupe asks the bridge to resume that session. A person can also resume a run, as
the next section says. Each resume is a new row, and its drawer links to the
run it continues. A run from before the workflow engine can show **Gave up**.

## Stop, resume and cancel

The project owner can control a run from the runs section of a card page and
from the drawer of a run. The card page lists only the runs in progress, so it
offers **Stop** and **Cancel request** for a stop. Resume an ended run, or run
a command again, from this page. Other people see the labels and no controls.
An interactive run has no controls, and its owner uses **Close session**
instead.

| Control | When it shows | What it does |
|---|---|---|
| **Stop** | the run is Queued, Resumed, Preparing or Running | asks the bridge to stop the run |
| **Resume** | the run ended as Blocked, Gave up, Failed, No result, Unfinished, Timed out, Lost, Stopped or Waiting for a person, and it has a session | asks the bridge to continue the session as a new run |
| **Run again** | a command run ended as Failed, Timed out or Lost | asks the bridge to run the command again as a new run |
| **Cancel request** | a stop, a resume or a rerun still waits for the bridge | withdraws the request |

A request waits until the bridge takes it. The row then shows the label of the
request: **Stop requested**, **Resume requested** or **Run again requested**.
When the bridge sends no heartbeat, the label ends with **, bridge offline**. A request that waits longer than the
`bridge.command_ttl_minutes` flag expires. The flag is 15 minutes by default.
See [Pause and commands](../reference/bridge-heartbeat.md#pause-and-commands).

The row shows a notice when a request did not work. An expired stop shows
**The stop request expired before the bridge took it.**, and an expired resume
shows the same text for a resume. An expired rerun shows **The request to run
again expired before the bridge took it.** A refused request shows **The bridge
refused:** and the reason the bridge gave, such as a session that is not on its
machine. The notice goes when a person sends a new request, or when the control
no longer applies to the run. A queued run of a paused bridge shows
**Waiting: bridge paused**.

A control can show and be disabled. Point at it to read the reason. When the bridge does not
report the `commands` capability, every control is disabled with **Update the
bridge to 1.5.0 or later to control its runs.** **Run again** is also disabled
with **Update the bridge to run a command again.** when the bridge does not
report the `rerun-command` capability.

The bridge refuses **Run again** when the rule of the run is gone or no longer
runs a command. It also refuses while the card has a run that is open on that
bridge. It refuses when the command of the rule reads a value of its first
event, such as `{to}`, because a rerun knows only the card and the project.
The bridge never runs a failed command again by itself.

A stop ends one run and holds nothing. The next event of the card can start a
new worker on it. To keep agents off the card, select **Make unmanaged** on
the card page. Loupe refuses a resume of a run while its card is unmanaged,
with **This card is unmanaged. Select Manage again first.** See
[Managed and unmanaged cards](board.md#managed-and-unmanaged-cards).

A cancel works only while the bridge has not received the request. A bridge
that is online receives a request in about a second, and a later cancel does
not recall it. Cancel is for a request that waits on an offline bridge.

A stop reaches the process group of the worker only. Work that the worker
started in another process tree keeps running, such as a PHPUnit run inside a
Docker container. A resume continues the session with a fixed prompt.

### Through the MCP

An agent can read the runs and the bridges, and stop, resume and cancel, through
the [MCP endpoint](mcp.md#what-the-tools-do). `worker_run_list` and
`worker_run_get` read the runs, and `bridge_list` reads the bridges.
`worker_run_stop`, `worker_run_resume` and `bridge_command_cancel` send and
withdraw requests. `card_hold` makes a card unmanaged, as **Make unmanaged**
does, and `card_release` makes it managed again. The connection acts as the project
owner, on its own project only.

Each run row also carries `usage`, `model`, `experiment`, `variant` and
`metrics`. The server keeps a metrics row for each run, with its cost, its
tokens, its model, its duration and its outcome. The metrics row stays after the
[retention](../reference/worker-runs.md#retention) sweep deletes the run.
The metrics rows start with the oldest run that the server held when it was
upgraded to this release. A run that the sweep deleted before then has no
metrics row, and the card cost total on the board still counts its usage.
`metric_query` reads the metrics rows over time, by run or by finished card, and
`metric_list` lists the metrics it takes.

The tools apply the same checks as the controls on this page. A resume needs a
session and an ended run in a state that can resume. A run with a request that still waits refuses a second
one, and so does a bridge that does not take commands. A refused run gives a
code and a message, and `worker_run_resume` takes up to 50 runs in one call.
There is no tool that pauses a bridge, and no tool that runs a command again.
Each run row carries its `kind`: `worker`, `interactive` or `command`.

## A warning on the card

A card whose latest outcome is **Gave up** or **Blocked** shows a warning on the
board. The warning names the state and the start of the run output, and links to
that run on this page. A later run of the card that ends another way clears it,
and so does a move of the card to another column. A run from an older bridge
names no column, so its warning stays in every column. An open run leaves the
warning in place until it ends.

## The usage total of a card

The runs section of a card shows one **Total usage** line below its runs in
progress. The line counts every run of the card, and the finished runs too. It
also counts the usage of runs that the retention sweep deleted. A card with no
run in progress shows the line alone when its usage is known. The line shows the
cost in US dollars, then the input, output, cache read and cache write tokens.
A large count is short, such as `45.3k` or `1.2M`.

The line can carry two marks:

| Mark | Meaning |
|---|---|
| **Estimated** | the bridge counted some tokens from the session transcript, or a model has no known price. The dollar total can be low |
| **n runs have no usage** | n worker runs started and closed, and reported no usage. The total leaves them out |

An open run, a run that never started and an interactive session never count
as runs with no usage. When no model of the card has a price, the line shows
the tokens and no dollars.

A card whose runs all come from an older bridge, or are all still open, shows
**Usage unknown** instead of $0.00. A run that reported usage with no models
spent nothing, so a card with only such runs shows $0.00. See
[Usage](../reference/worker-runs.md#usage) for how a bridge reports usage.

## The cost of finished cards

The **Cost** tab, at `/projects/{project}/worker-runs/cost`, shows one bar for
each day, week or month in which a finished card with usage finished. A card is
finished when it sits in a terminal column, such as **Done**. The cost of a
card is the dollar total of every usage row of the card. The date of a run does
not matter, so a card that finished this week keeps the runs of earlier weeks.

The height of a bar is the average cost per card of its period: the total of
its cards divided by the number of cards. A period with no finished card has no
bar, and keeps its place on the time axis. A week starts on Monday.

A dashed line crosses the chart at the median cost per card, the same amount as
the first figure above the chart.

The dollars are the API list price that claude reports. On a subscription, you
do not pay this amount.

The controls above the chart change what it shows:

| Control | Effect |
|---|---|
| Range | **30 days**, **90 days** or **All time**, by the date the card finished. The default is 90 days |
| Split | **No split**, **By rule** or **By model**. A split stacks each bar in one colour for each rule or model, and adds a legend. Each part is the average of that rule or model over the cards of the period |
| Group | **Per day**, **Per week** or **Per month**. The default follows the range: per day for 30 days, per week for 90 days and per month for all time. A new range goes back to its default |
| Rule and model | keep only the matching part of each bar. A card with no matching part leaves the chart |

A rule or a model keeps its colour when a filter hides other rules or models.
With more than eight rules or models, the first seven in name order keep their
colours, and the rest share one grey, labelled **Other**.

Three figures above the chart follow the controls: the median cost per card,
the total cost, and the number of finished cards with usage.

A wide bar shows its number of cards and its average above it. A narrow bar
shows **×N** when it holds N cards. A bar carries the marks of the
[usage total](#the-usage-total-of-a-card). The whole bar is hatched when a card
of the period includes an estimate or a model with no price. A **+** after the
average, or above a narrow bar, means that some runs of a card have no usage.
The model filter does not apply to the **+**, because a run with no usage has no
model.

Point at a bar, or move to it with the Tab key, to see its period, its number of
cards, its average and its parts. The hover card then lists each card of the
period with its cost. A bar of one card opens that card when you click it or
press Enter. **Show the data as a table** lists each card as text, with the
exact date it finished.

When no finished card with usage falls in the range, the tab shows **No
finished card with usage in this range.**

## Interactive sessions

A Claude Code session that a person runs on a card, such as
`/loupe:product-design`, calls the MCP tool `card_run_open`. Loupe then records
an interactive run on the card, with the state **Running** and the skill name
as its rule. No bridge holds this run, so it has no exit code and no output.
The heartbeat timeout never touches it.

A work entry with `action: interactive` can open the session in a terminal
when the workflow asks for that work. The bridge then records the run first, in
the state **Running**, with the work kind and the bridge. When the session calls
`card_run_open`, it takes over that run, so the page shows one row. A launch
that fails shows **Never started**, with the bridge. Its reason holds the exit
code and the output of the launcher. See
[the command-line bridge](../extending/cli-bridge.md#interactive-action).

These actions close an open interactive run, and it then shows **Closed**:

- The session calls `card_run_close` when it ends.
- The owner selects **Close session** on the running row, in the runs section
  of the card.
- The card moves to another column. A move inside the same column closes
  nothing. The delete of a column moves its cards, so it closes their runs.
- A person deletes the card.

When the session calls `card_run_close`, Loupe asks the bridges that follow
the project for the usage of the run. The bridge that holds the session
transcript sums the tokens between the start and the end of the run, subagents
included, and reports them. The run then shows its usage, marked
**Estimated**, and the card total counts it. A bridge older than this feature
never gets the request. A run that closes another way gets its usage when the
same session later calls `card_run_close` on that card. A run with no bridge
that holds its transcript keeps no usage.

No timeout closes the run. A session that stops with no call leaves its run open
until the owner closes it or the card moves. While the run is open, a bridge
rule with `card: { interactiveRun: false }` skips the card. See
[the command-line bridge](../extending/cli-bridge.md#events-endpoint).

## A missing record means unknown

A missing record means "unknown". It never means that the worker did not run.

The bridge holds its retry queue in memory. A bridge that stops between a worker
finishing and its report landing loses that outcome for good. So read this page
as what the server was told, not as a complete history of every worker.

The page carries this caveat below the list, on every project.

## Bridge health

Open **Agents** in the project sidebar to inspect bridge heartbeats.
The page lists only bridges that follow the current project.
Its summary distinguishes no connections, healthy connections, stale connections, and a mix of healthy and stale connections.
Heartbeat health does not show whether an individual worker is running or available for work.

Each bridge card shows the name of the bridge as its heading. The bridge sets
the name in its rule file, and the host name of its machine is the default. A
bridge with no name shows the last 12 characters of its id. The run list, the
run drawer and the board show the same label.

Two bridges of one account cannot hold the same name. The bridge that asks
second gets no name and shows the end of its id. Its card shows a warning that
another bridge holds the name. The warning stays until the other bridge frees
the name, or until you give this bridge a different name. See
[The bridge name](../reference/bridge-heartbeat.md#the-bridge-name).

A bridge that runs worker pools reports their use with each heartbeat. The
card of the bridge then lists each pool on one line: the pool name, the
workers in use of the pool size, and the runs in the queue. The heading gives
the time of the heartbeat that carried the counts, because the counts are
correct at that time only. A later heartbeat with no pool report keeps the
counts and their time. The counts cover every project that the bridge follows, not only the
current project. A bridge that sends no pool report, such as an older bridge,
shows no pools.

The page also has a **Hooks** section. It shows one block for each of your
bridges whose heartbeat names the project, the latest heartbeat first. Each
block shows the last 12 characters of the bridge id, with the full id in the
tooltip, and the time of the last heartbeat. Under it, each
[hook package](../extending/bridge-hooks.md) of the bridge has one row for each
event it defines. A row shows the package, its ref, the event, the bridge and
the time of the last run. Its chip reads OK, Failed, Timed out or Not run yet. A
failed or timed out row shows the end of the hook's output, or the error when
the hook could not start. A bridge with no hook shows "No hook is installed."
Each heartbeat replaces the rows of its bridge, so a bridge that stops keeps its
last list.

The project owner can pause a bridge. Open the menu of the bridge card and
select **Pause new work**. A paused bridge starts no queued run, and its
running workers go on. **Resume new work** ends the pause. The server keeps the
pause, so a bridge that is off applies it when it comes back. A restart of the
bridge keeps the pause too.

The health chip shows **Pausing** until the bridge reports the pause, and then
it shows **Paused**. After **Resume new work**, it shows **Resuming new work**
until the bridge reports the change. A quiet bridge shows **Stale** whatever its pause.
A **Paused** block on the card gives the time of the pause and the person who
asked for it. A bridge that does not report the `commands` capability cannot
pause, and the menu says to update it to 1.5.0 or later.

## The output

Select a row to open a read-only drawer without leaving the list.
It shows the attempt ID, card, work kind, experiment, worker pool, bridge, session and duration. A run with no worker pool shows no pool row. An interactive run shows a bridge only when a bridge launched it.
A resume also shows a link to the run it resumes.
The drawer shows the result status, the reason the bridge skipped a resume, and each extra result field the worker gave.
It lists each state the run reached, oldest first, with the time of each state, and then the time the first report arrived.
Agent identity and the triggering event remain unreported rather than inferred.
Press Escape or select **Close** to return focus to the row.

Select **Copy output** to copy the original output text.
If the browser refuses clipboard access, the drawer keeps the text available for manual copying.
The drawer of a run shows its controls, as
[Stop, resume and cancel](#stop-resume-and-cancel) says.

The drawer shows the worker's output in full, under **Output**. A run that
succeeded shows its output the same way a run that failed does, because a
reader of a run record is usually debugging.

The output is whatever the agent printed, up to 4000 characters. Nobody reviews
it before it reaches this page. It may carry file contents, paths or anything
else the agent chose to say, and the server shows it as plain text.

## Live updates

The list, the runs section of a card page and the board reload when a report
or the timeout sweep changes a run of the project. They also reload when a
person sends or cancels a request, and when a bridge answers a request or a
request expires. A pause or a bridge that reports its pause reloads them too. They also reload after the page
reconnects to the hub, for any change the page missed. This needs a Mercure hub
and the `live_updates.enabled` flag. Without them, the page shows a change on
its next load.

An open drawer holds the reload. The list reloads when you close the drawer.

## Search and filters

The search box covers the card number, the work kind and the output text.
It matches whole words and accepts quoted phrases and a leading `-` to exclude a word.
Paste a complete run ID to find that attempt in the current project.
Run IDs match without regard to letter case, and the outcome and bridge filters still apply.

Two filters narrow the list further:

- **Outcome** keeps one state. A link saved with `outcome=succeeded`, `outcome=no-result`, `outcome=failed` or `outcome=not-started` still works. `outcome=closed` keeps the closed interactive runs. **Open runs** (`outcome=open`) keeps every queued, resumed, running and stopping run, of both kinds.
- **Bridge** keeps one bridge, and lists each bridge by its name. It appears once a second bridge has reported.

Every control lands in the URL, so a filtered view is a link you can share.
Select **Clear** to go back to the whole list.
Search and Outcome stay on one row on narrow screens.
With enlarged text, the row scrolls horizontally when needed. Keyboard focus brings each control into view.
The Bridge filter remains available below them when space is limited.
It stays within the form width at enlarged text sizes.
Long work kinds wrap within their column, while outcome badges keep their compact height.

## A card that no longer exists

A run record holds the card identifier as a plain value rather than as a
reference, so deleting a card leaves its run history intact. The card number
still carries the link, and that link answers 404 once the card is gone. The run
row itself keeps reading correctly.

An instance with the board feature off shows the card number as plain text,
because it has no card page to link to.

## How long a run is kept

The server keeps a run record for 180 days, counted from when the report
arrived. See [the worker run API](../reference/worker-runs.md) for the retention
window and the feature flag that sets it.
