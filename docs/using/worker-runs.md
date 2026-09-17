---
title: "Worker runs"
description: "The page that shows what a command-line bridge told a project about the Claude Code workers it ran."
---

A [command-line bridge](../extending/cli-bridge.md) runs a Claude Code worker
for each board event one of its rules matches. Every worker the bridge reports
becomes one row on the project's **Worker runs** page.

Open the page from the project sidebar, or go to
`/projects/{project}/worker-runs`. Anyone who can view the project can read it.

## What a row shows

| Column | Meaning |
|---|---|
| Card number | the card the worker was started for. It links to the card while the board feature is on |
| Rule name | the bridge rule that matched the event |
| Started | when the worker started, on the bridge clock |
| Took | how long the worker ran |
| Outcome | **Succeeded** for exit code 0, **Failed** for any other code, **Never started** when the process never ran |
| Bridge | the last 12 characters of the bridge's own identifier |

A run that never started carries the reason instead of an exit code, such as a
missing `claude` binary.

The list reads newest report first, 20 runs to a page.

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

Every row shows the worker's output in full, collapsed. Open **Output** to read
it. A run that succeeded shows its output the same way a run that failed does,
because a reader of a run record is usually debugging.

The output is whatever the agent printed, up to 4000 characters. Nobody reviews
it before it reaches this page. It may carry file contents, paths or anything
else the agent chose to say, and the server shows it as plain text.

## Search and filters

The search box covers the card number, the rule name and the output text. It
matches whole words, and it accepts quoted phrases and a leading `-` to exclude
a word.

Two filters narrow the list further:

- **Outcome** keeps one of succeeded, failed and never started.
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
