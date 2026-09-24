---
title: "Worker runs"
description: "The page that shows what a command-line bridge told a project about the Claude Code workers it ran."
---

A [command-line bridge](../extending/cli-bridge.md) runs a Claude Code worker
for each board event one of its rules matches. Every worker the bridge reports
becomes one row on the project's **Worker runs** page. An interactive Claude
Code session on a card gets a row too, as
[Interactive sessions](#interactive-sessions) says.

Open the page from the project sidebar, or go to
`/projects/{project}/worker-runs`. Anyone who can view the project can read it.

## What a row shows

| Column | Meaning |
|---|---|
| Card number | the card the worker was started for. It links to the card while the board feature is on |
| Rule name | the bridge rule that matched the event |
| Started | when the worker started, on the bridge clock. A run that has not started shows when its first report arrived |
| Took | how long the worker ran. A run that is still open shows how long it has run so far, and an open interactive run shows "running for" in front. A run with no start, or a run that closed with no reported end, shows nothing |
| Outcome | the state of the run, from the list below |
| Bridge | the last 12 characters of the bridge's own identifier. An interactive run shows the tag **Interactive session** instead |

A run that never started carries the reason instead of an exit code, such as a
missing `claude` binary.

The list reads newest first, by when the first report of each run arrived, 20
runs to a page.

## The states of a run

| State | Meaning |
|---|---|
| **Queued** | the bridge accepted the event, and the run waits for its turn |
| **Replaced** | a newer event for the same card and rule took the place of this run |
| **Resumed** | the ask the session waited on closed, and the bridge resumes the session |
| **Skipped** | the session already read its answers, so the bridge did not resume it |
| **Running** | the worker runs |
| **Waiting for a person** | the rule's chain cap stopped the run, and a move by a person starts a new one |
| **Dropped** | the bridge stopped, a rule died, or a reload removed the rule, before the run started |
| **Succeeded** | the worker exited with code 0, with a result line |
| **No result** | the worker exited with code 0, with no result line |
| **Failed** | the worker exited with any other code |
| **Never started** | the worker process never ran |
| **Timed out** | the bridge stopped sending its heartbeat while the run was open |
| **Lost** | the bridge reconnected, and it no longer holds the run |
| **Closed** | the interactive session ended, or its card moved to another column |

Queued, Resumed and Running are open states. A bridge that dies cannot close
its runs, so Loupe closes them. **Timed out** is a guess: a bridge can go quiet
and come back, and a later report from it replaces the guess. **Lost** is a
fact: the bridge came back without the run, so the run can no longer end. See
[the worker run API](../reference/worker-runs.md#timed-out-and-lost) for the
rules.

The result line is the line that starts with `STAGE RESULT:`, which every
worker prompt asks for. A **No result** run exited cleanly but may have stopped
before its work was done, so read its output. A run from an older bridge
carries no result check, and its outcome comes from the exit code alone.

## Interactive sessions

A Claude Code session that a person runs on a card, such as
`/loupe:product-design`, calls the MCP tool `card_run_open`. Loupe then records
an interactive run on the card, with the state **Running** and the skill name
as its rule. No bridge holds this run, so it has no bridge, no exit code and no
output. The heartbeat timeout never touches it.

These actions close an open interactive run, and it then shows **Closed**:

- The session calls `card_run_close` when it ends.
- The owner selects **Close session** on the running row, in the runs section
  of the card.
- The card moves to another column. A move inside the same column closes
  nothing.

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

## The output

Select **View attempt** to open a read-only drawer without leaving the list.
It shows the attempt ID, card, rule, bridge, session and duration. An interactive run shows no bridge.
It lists each state the run reached, oldest first, with the time of each state, and then the time the first report arrived.
Agent identity and the triggering event remain unreported rather than inferred.
Press Escape or select **Close** to return focus to the opening button.

Select **Copy output** to copy the original output text.
If the browser refuses clipboard access, the drawer keeps the text available for manual copying.
The drawer has no Stop or Retry controls.

Every row shows the worker's output in full, collapsed. Open **Output** to read
it. A run that succeeded shows its output the same way a run that failed does,
because a reader of a run record is usually debugging.

The output is whatever the agent printed, up to 4000 characters. Nobody reviews
it before it reaches this page. It may carry file contents, paths or anything
else the agent chose to say, and the server shows it as plain text.

## Live updates

The list and the runs section of a card page reload when a report or the
timeout sweep changes a run of the project. They also reload after the page
reconnects to the hub, for any change the page missed. This needs a Mercure hub
and the `live_updates.enabled` flag. Without them, the page shows a change on
its next load.

An open drawer holds the reload. The list reloads when you close the drawer.

## Search and filters

The search box covers the card number, the rule name and the output text.
It matches whole words and accepts quoted phrases and a leading `-` to exclude a word.
Paste a complete run ID to find that attempt in the current project.
Run IDs match without regard to letter case, and the outcome and bridge filters still apply.

Two filters narrow the list further:

- **Outcome** keeps one state. A link saved with `outcome=succeeded`, `outcome=no-result`, `outcome=failed` or `outcome=not-started` still works. `outcome=closed` keeps the closed interactive runs.
- **Bridge** keeps one bridge. It appears once a second bridge has reported.

Every control lands in the URL, so a filtered view is a link you can share.
Select **Clear** to go back to the whole list.
Search and Outcome stay on one row on narrow screens.
With enlarged text, the row scrolls horizontally when needed. Keyboard focus brings each control into view.
The Bridge filter remains available below them when space is limited.
It stays within the form width at enlarged text sizes.
Long rule names wrap within their column, while outcome badges keep their compact height.

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
