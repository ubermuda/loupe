---
title: "The project board"
description: "The columns each board has and how an owner changes them, how cards are ordered, the board screen a person drags cards on, and the MCP tools an agent drives them with."
---

Every project has one board, and the board holds cards. A card describes one
piece of work: what it asks for, how urgent it is, and which column it sits in.
A person works the board on its own screen. An agent reads and writes the same
board through the MCP endpoint.

Every instance has the board. [Site review](site-review.md) writes each note to
a card.

## Columns

Each board has its own columns. A card sits in exactly one column. A person
sees the column's label, and the tools name the column by its slug.

A new board starts with these columns:

| Label | Slug | Flag |
|---|---|---|
| Backlog | `backlog` | backlog |
| Next | `next` | |
| In progress | `in-progress` | |
| Done | `done` | terminal |

A board keeps these columns until its owner changes them. A project that
existed before columns were configurable got the same columns.

### Backlog

Every board has exactly one Backlog, and its slug is always `backlog`. Backlog
holds the cards that wait for their turn. The board does not draw it as a
column. The **Backlog** button in the board header opens the Backlog page,
which lists these cards. See [The Backlog page](#the-backlog-page).

A card created with no column lands in Backlog. That covers the create form,
which preselects Backlog, a card raised from the review widget, and
`card_create` with no `status`.

Nobody can rename, reorder or delete Backlog, and it is never terminal. Board
settings does not list it, and no other column can take the slug `backlog`.
The tools still list Backlog, so an agent or a workflow template can name `backlog`.

A board from an earlier release had a default column instead, which the owner
could rename. The upgrade turns that column into Backlog, with the label
Backlog and the slug `backlog`, so a custom name is lost. When its old slug was
not `backlog`, a prompt that named the old slug stops matching. When another column held the
slug `backlog`, the upgrade moves its cards to the end of Backlog, in rank
order, and deletes that column.

### Terminal columns

A terminal column holds finished work. A card that enters one gets a completion
time. A board has at least one terminal column, and it can have more. See
[Terminal columns behave differently](#terminal-columns-behave-differently).

### Change the columns

The project owner changes columns in **Board settings**. An agent connected to
the project can change them through the [MCP column tools](mcp.md#what-the-tools-do).
The board itself has no column controls, and its columns cannot be dragged. No
API route writes a column.
Another reader of the board sees no column controls.

Open **Board settings** to manage columns beside the other project settings.
Below the columns, **Finished work on the board** sets how many days of
finished cards each terminal column shows.
The **Board columns** section lists the columns in order. Each row has
**Move up** and **Move down** arrows, a gear button, and a delete button. The
gear opens a dialog with the column name, **A finishing point for completed
work**, and **Colour**. **Save column** saves them together. Each action
returns to this section.

Reorder and configure changes check the state shown when the form opens.
If another editor changes that state first, Loupe refuses the stale change.
A refused configure keeps your draft in its dialog. Copy it before you reload.
A refused reorder shows the current order with an error message.

The owner adds a column with **Add a column** in board settings. A new column
is not terminal. The dialog preselects a colour that no
other column on the board uses, and the owner can pick another before saving.
When every colour is in use, Loupe picks one at random. Board settings shows a
**Terminal** badge on a terminal column.

The last terminal column has no delete button. Make another column terminal
first.

A column that becomes terminal gives a completion time to each card in it that
has none, and resolves the open feedback of those cards. A column that stops
being terminal ranks its cards by completion time, then clears their completion
times.

### Labels and slugs

A label holds at most 100 characters. The slug follows the label: Loupe
transliterates the label to ASCII, lowercases it, and joins the words with
hyphens. "In progress" gives `in-progress`, and "Café" gives `cafe`.

Loupe refuses a label when one of these is true:

- The label has no letter or digit that transliterates, such as an emoji alone.
- The slug already belongs to another column of the board. "Done" and "done!"
  both give `done`.
- The label is text the app uses internally, such as a translation key.

The column dialog shows the new slug as you type, and it shows a refusal before
you save. A seeded column shows its label in the reader's language. A rename
stores the label as typed, so a renamed column is no longer translated.

A rename keeps the workflow working, because the template links each slot to a
column by its id. A rename that changes the slug still breaks every outside
reference to the old slug. Loupe cannot see these references, so it cannot warn
you about them:

- An agent prompt, a skill or a saved instruction that names the old slug.
- A `status` argument that a script passes to an MCP tool.

A rename that changes the slug writes a `board.column_renamed` event with the
old slug and the new slug. A rename that keeps the slug writes no event.

### Delete a column

The delete button of a column in board settings opens a dialog. The dialog for
an empty column asks for a confirmation only.

The dialog for a column that holds cards shows the count and asks for a target
column. Every card moves to the target. A card that enters a terminal column
gets a completion time if it has none. A card that enters another column loses
its completion time, and joins the end of that column.

Each moved card gets its own `board.card_moved` audit record. The outbox gets
one `board.column_deleted` event, which names the moved cards. A bulk move
writes no `board.card_moved` event. The workflow checks each moved card again,
as for any other move. A deleted column that a slot of the template links
leaves that slot empty, and a card that needs the slot pauses with "workflow
slot missing".

## How cards are ordered

Each card in a column holds a rank, counting from 0. A column reads as one
ranked list.

The rank is a plain integer, and Loupe renumbers a column from 0 after every
change. A column carries no gaps, so the rank a tool reports is the card's real
place in its list.

A move inside one column takes a target rank and puts the card there. A move to
another column appends the card to the end of that column, and it renumbers the
column the card left so no gap remains. A delete renumbers the column the card
leaves in the same way.

The MCP tools carry no rank argument. `card_create` appends a new card to its
column, and `card_update` appends a card whose column changed. An agent cannot
re-rank a column.

Two cards that tie on rank read oldest first, and then by id, so a column comes
back in the same order on every read.

## Terminal columns behave differently

A terminal column sorts by completion time, newest first. It keeps no rank, and
every card in a terminal column reports the rank 0.

A card that enters a terminal column is stamped with the moment it arrived. A
card that moves inside a terminal column, or from one terminal column to
another, keeps that first stamp. A card that leaves for a column that is not
terminal loses the stamp, and takes a new one if it comes back.

The board screen shows the last 3 days of each terminal column, and a history
page carries the rest. The window is a board setting. The project owner sets it
from 1 to 30 days under **Finished work on the board** in board settings.
`card_list` applies no such window. Every finished card is on the board it pages
through, however old it is.

## The board screen

The board is at **`/projects/<project>/board`**, and the project sidebar links
to it. The columns read side by side, in board order. Each card shows its
number, its title, its type, how many pull requests it links to, and how many
review comments still wait on it.
A card whose latest worker run gave up, is blocked, failed or has no result also
shows a warning. A newer run of the card clears it. See
[A warning on the card](worker-runs.md#a-warning-on-the-card).

A card in an open column shows one state mark at the end of its top row, when it
is in a state. The mark says what the card needs now:

| State | Means |
|---|---|
| **Stuck** | The card is paused, its last worker run gave up, was blocked, failed or had no result, a pull request has failed checks or a conflict and no fix is under way, or a pull request has been ready to merge for longer than the stuck delay and no merge rule matches it |
| **Needs you** | A document of the card waits for your review, a pull request waits for your approval, or an inbox question names the card |
| **Working** | Work waits for a bridge to take it, a worker runs on the card, or Loupe asked the forge to change a pull request and the forge has not answered |
| **Waiting** | A card that blocks this card is still open |

When several states apply, the tile shows the first in the order **Stuck**,
**Needs you**, **Working**, **Waiting**. Hover the mark, or focus it, to read
why the card has the state, since when, and a link to the card. The tooltip stays
open while the pointer moves into it. The **Working** mark spins, and stays still
when your system asks for less motion. A card in a terminal column shows no mark.
A pull request that waits for your approval shows **Needs you** and never
**Stuck**. A pull request that Loupe never read adds no state.

The tile also shows **Unmanaged** when a person or an agent made the card
unmanaged. See [Managed and unmanaged cards](#managed-and-unmanaged-cards). The
mark changes with no reload. The **List** view and the Backlog page keep the
badges **Checks failed**, **Conflict**, **Automation blocked** and **Paused**,
and the warning of the last worker run. See
[A warning on the card](worker-runs.md#a-warning-on-the-card).

Each card type and each column has a colour, and every page that names one uses
the same colour. The workflow template sets the colour of a card type, and it
also declares which types the board offers. The owner picks a column's colour from twelve in its
**Colour** setting. The colour stays with the column when the columns move.

The page header is one row: the title, the search, the card count, the
**Board** and **List** switch, a **Board settings** button with a gear icon,
and **Add card**. It wraps on a narrow screen, and it stays in place when the
cards scroll.

A second header row holds a **Backlog** button. The button shows how many cards
Backlog holds, and it opens the Backlog page. It also takes a dropped card.

Each column head shows the column colour, its label and its card count. Each
column scrolls its own cards, and the board scrolls sideways as one block.

Drag a card to move it. The whole card is the handle. Where you drop the card
decides what the move does.

- Drop it inside its own column to change its rank in that column.
- Drop it in another column to change its column. The card takes the end of
  that column.
- Drop it on the **Backlog** button to send it to the end of Backlog. The card
  leaves the board and keeps its epic.

A terminal column takes a drop like any other column. It keeps no rank.

The card follows the pointer as you drag, and a gap opens where a release would
put it. The server answers a drop with the moved card alone. Only the columns
involved change, and the scroll position and the filter stay as they are. A move
the server refuses puts the card back where it started, and a message says so.
The server refuses a move to a column that no longer exists.

**Add card**, in the page header or under a column, opens the create form in the card drawer. A column's Add card preselects that column. The button reads **Creating…** while the card saves. Then the drawer closes, and the card appears in its column with no reload of the board. Without JavaScript, the same link opens the form as a page. Under each terminal column, a link opens the
history page at **`/projects/<project>/board/terminal/<column id>`**. That page
lists every card in the column, newest completion first, 25 to a page. The
older address **`/projects/<project>/board/done`** still works. It opens the
history page of the board's first terminal column.

### The Backlog page

The Backlog page is at **`/projects/<project>/board/backlog`**. It lists the
cards in Backlog, 25 to a page. Each row shows the card number and title, the
type, the epic, the count of pending feedback and the date the card was added.

Above the list, search the title and the body, and filter by type and by epic. The type filter offers the types that the workflow
template declares.
The epic filter offers **Any epic**, **No epic**, and each epic with a card in
Backlog. **Clear** removes every filter, and a count shows how many cards
match.

The list shows the newest cards first. Click the **Type**, **Epic** or
**Added** column header to sort by that column, and click it again to reverse
the order. An arrow shows the current order. The **Epic** sort puts the cards
with no epic last in both directions. A filter change keeps the sort.

The **Move to** menu of a row sends the card to the end of a board column. Tick
rows to show the bulk bar. It has a button for the first column that is not
terminal, such as **Move to Next**, a **Move to** menu for any board column, and
**Clear**. A bulk move takes the ticked cards of one page. They keep
the rank that the board holds for them, whatever the sort of the page.
When one move is refused, no card moves.

An empty Backlog shows **The Backlog is empty**. Filters that match no card
show **No card matches** and a **Clear filters** button.

When someone else changes a card or the columns of the board, the page shows
**The board changed.** and a **Reload** link. The change can be outside the
Backlog. The page does not reload by itself, so a
selection or an open menu stays. A change made on this page shows no notice.
The notice needs live changes, as the board does.

### Live changes

Every open board of the project shows a change as it happens, with no reload.

- When someone else adds, edits, moves or deletes a card, that card changes in
  place. The other cards, the scroll position and the filter stay.
- On a board with epic lanes, a card also changes in place, in the cell of its
  lane and column. The head of an epic lane changes in place too.
- A card that someone else changed gets a short highlight. With reduced motion
  on, the highlight is a still outline.
- A card that you drag waits. The change shows when the drag ends.
- When a child joins, leaves, finishes or reopens, the progress of its epic
  changes. When an epic lane appears or disappears, the board adds or removes
  the lane.
- When the owner adds, renames, reorders, flags or deletes a column, only that
  column changes in place. A change to an epic lane or to the rules banner
  also updates only that lane or banner. The cards of a deleted column or lane
  then move to their new place.
- A change to the columns, the lanes or the banner waits while you drag a card,
  while a move you made is not saved yet, or while a dialog is open. You can
  drop a card into a column that appeared while the board was open.
- When the board cannot show a change to a card after a few tries, the card
  gets a dashed outline, with the tooltip **This card may be out of date**.
  The next change to that card, or a reload of the page, removes the mark.

When the connection to the server stops for about 5 seconds, the toolbar
shows **Live updates paused**. When the connection comes back, the board asks
the server which cards changed, and only those cards update. A change to the
columns or to an epic lane updates only that column or lane. The sign then goes
away. When the board cannot catch up after a lost connection or a column change,
it tries again a few times, unless the server refuses the request. Then the toolbar shows **Live updates stopped.
Reload the page to catch up.**

Live changes need a Mercure hub and the `live_updates.enabled` flag, see
[Environment variables](../reference/environment.md). If either is missing,
your own moves, edits and new cards still show at once. A change by someone
else shows on the next load of the board. The card drawer also shows changes by
others while you edit, see [The card page](#the-card-page).

### The card page

A card has its own page at **`/projects/<project>/board/cards/<card id>`**. The
card id is the UUID, not the number.

At the top of the **Overview** tab, a **Status** box names the state of the card
and how long it has held, such as **Stuck for 2 h**. It says why the card has
the state, since when, and what clears it. It lists the other states that apply
under **Also applies**. A card in no state, and a card in a terminal column, show no
box. A reason has a start time when Loupe stores one. Otherwise the box leaves
the time out.

The page carries the full Markdown body, every pull request link, and the times
the card was created, last changed and completed. Its **Feedback** tab shows the
site-review notes on the card. See
[Review feedback on a card](#review-feedback-on-a-card). Its Status field is a
column control, which moves a card
with no drag. That is the way to move a card from a keyboard.

Each pull request link shows the last state that Loupe read, as chips. An open
pull request shows **Open** or **Draft**, then its checks: **Checks passed**,
**Checks pending** or **Checks failed**. A failed chip names the checks that
failed. The row then shows **Conflict**, **Behind base** or **Merge blocked**
when one applies, and the review: **Approved**, **Approval outdated**,
**Changes requested** or **Review required**. A merged or closed pull request
shows **Merged** or **Closed** alone. A link that Loupe never read shows **Not reported**, and a link
with no usable URL shows **Unavailable**. See
[What GitHub tells a card](#what-github-tells-a-card).

**Approval outdated** shows when the approval does not cover the newest commit.
A merge from the base after the approval keeps it covered, as
[Pull request waits](inbox.md#pull-request-waits) describes. A new approval of
the newest commit shows **Approved** again.

When the project syncs an approved pull request that is behind, each open pull
request whose base is the default branch also shows one line about its sync. It shows
**Waits for an approval**, **Conflicts with the base**, **Waits its turn behind
#N**, **Synced, checks running** or **Sync failed** with the cause. A pull
request with nothing to wait for shows no line. See [Automation](#automation).

When an approval did not move the card because a blocker is open, the Workflow
panel says what the card waits for.

The card page and the drawer update live, with no reload. They update when the
card moves, when one of its pull requests changes, when the automation acts,
and when a worker run of the project changes. The open tab stays open. A form
with unsaved input keeps that input, and an open dialog holds the update until
it closes. Live updates need the same hub and flag as the board. See
[Live changes](#live-changes).

The page also lists up to five agent runs of the card that are still in
progress, with the rule that started each run, when it started and its state.
A run opens its details on the **Runs** tab of the Activity page. A finished
run leaves this list, and the **Run history** link shows it. The project owner
sees the runs section on every card, also on a card with no runs.
The project owner can stop a run in progress, or cancel a stop that still
waits for the bridge, from this list. A stop ends one run and holds nothing,
so the next event can start a new worker on the card. Resume a finished run
on the **Runs** tab of the Activity page. See
[Stop, resume and cancel](worker-runs.md#stop-resume-and-cancel). To keep
agents off the card, see [Managed and unmanaged cards](#managed-and-unmanaged-cards).

The **History** tab lists what happened to the card, newest first, on a
timeline. A row says who created the card and in which column, and who moved
it from one column to another. When Loupe moved the card on its own, a line
under the row says why, for example after a pull request merged. An agent's
move inside a worker run names that run on the same line. The tab also
records when the automation asked for a fix or stopped, with the reason, when
a pull request was ready to merge, and when a verdict was sent from the
site-review widget. A finished agent run shows its
rule, its duration and its result, and it links to the run while the run is
kept.

A row names a person by their full name. An agent's change reads **Agent for**
and the name of the person the agent works for. A change by the app reads
**Loupe**. A change from the site-review widget reads **A reviewer**, because
the widget does not name its visitor. A verdict is the exception, because the
reviewer signs in to send it, so its row names the reviewer. A deleted account reads **A deleted
user**. The tab shows 50 rows, and **Show older** loads the next 50 in
place. Loupe records history from the version that added this tab, so an
older card starts with an empty tab.

A card with links to other cards shows a **Linked cards** table. Each row gives
the kind of link, the other card's number, its title and its column. A row opens
that card in the drawer. A card with no links shows no table. See
[Cards linked to a card](#cards-linked-to-a-card).

When the inbox is on, the page also lists the inbox items linked to the card,
and you can answer them there. See
[On a card page and a document page](inbox.md#on-a-card-page-and-a-document-page).

On the board, the Workshop and a document, a card opens
in a drawer that slides in from the right. The drawer shows the same content as
the card page.

**Edit** opens the card for a change to its title, body, type, column and
links. In the drawer, the form replaces the card. The button reads **Saving…**
and then **Saved** for three seconds, and the form stays open. The board changes
only the card you saved. As a page, saving returns to the card.

The edit form remembers the text it opened with. When someone else changes the
title or the body while the form is open, the form shows **This card changed
since you opened it**, with a link to the latest version and a **Save anyway**
button. A save over that change asks the same question, and keeps the text you
typed. A move to another column alone shows no notice. When someone deletes the
card, the drawer shows **This card was deleted** and offers no save.

**Delete** asks for a confirmation first, then removes the card, its
links and its feedback. A delete cannot be undone, and the number the card held is not
issued again.

The create form and the edit form carry a **Linked cards** block. Each row picks
a card and a kind. Type a fragment of a title or a card number, and the field
offers the matching cards of the project. The **Add a linked card** button adds
a row, and the cross at the end of a row removes it. Saving replaces the card's
whole set of links.

#### Managed and unmanaged cards

A project can run a workflow template. A card of such a project is managed:
it follows the template. The workflow moves the card and requests work for it
when a condition of the template becomes true. A person may make only the moves
that the template lists. When you drop a managed card in a column that the
template does not list, the board offers to make the card unmanaged and then
move it. An agent that tries such a move gets an error that names `card_hold`.

Each existing project got a template when the engine was switched on. A board
with a column for each slot of Lifecycle got Lifecycle, and every other board got
Simple. [Workflows](workflows.md) describes both templates. The workflow
treated every condition that was true at that time as handled, so nothing moved
at once.

An unmanaged card is outside the workflow. The workflow makes no move and
starts no work on it, and no bridge starts a worker on it. A person may move it
to any column. A run that is in progress goes on, so stop it as well if it must
end now. Loupe refuses a resume of a run of the card while the card is
unmanaged. A run that waits in a queue stays there, and it starts when the card
is managed again.

To make a card unmanaged, select **Make unmanaged** in the runs section of the
card page. The section then shows **Unmanaged: the workflow makes no move and
starts no work on this card.** Select **Manage again** to end it. An agent uses
the `card_hold` and `card_release` MCP tools.

These actions make the card managed again:

- A person selects **Manage again** in the runs section, or an agent calls
  `card_release`.
- A person deletes the column of the card, or the card.

When a card is managed again, the workflow takes the card as it is at that
time. Each rule whose condition is true then fires, also when it was true
before. A rule does not request work a second time while its request is live.
Each rule gets a fresh work budget, and the timeout of an open work request
starts again. A card that the workflow paused stays paused until its pause
ends.

#### The Workflow panel

The card page and the drawer show a **Workflow** panel when the panel has
something to show. The runs section shows whether the card is unmanaged.

When the workflow paused the card, the panel shows the pause. It gives the kind
of pause, the reason, the time and the condition that ends the pause. The
workflow pauses a card when a rule of the template asks for it, when too many
attempts are refused, when no bridge takes the work in time, or when a rule
reaches its work limit.

The panel also shows the slot of the card, the condition that the card waits
for and the next action. It also shows the last refusal, with its reason, its
time and the number of attempts. An unmanaged card shows none of these.

A rule can fail to read its facts. Then the condition line gives the reason:
the workflow could not read the source, the source is off on this instance, or
the condition no longer exists. The rule waits, and the workflow tries it again
at the next evaluation.

An unmanaged card is a different control from the pause of a bridge. **Pause
new work** on the Agents page stops one bridge from starting any queued run,
on every card. See [Bridge health](worker-runs.md#bridge-health).

## Epics and lanes

An epic is a card of a type with the children capability. The shipped templates
declare the type `epic` with that capability. An epic groups other cards, its
children, so a large feature can go to many small cards and still read as one
piece of work. The workflow template declares the card types, and a template
can give the capability to another type. The rest of this section says "epic"
for a card of such a type.

### Parents

A card can have one epic as its parent. Set the parent in the **Parent epic**
field of the card form, or with `parentCardId` in the MCP tools. Loupe refuses
these changes:

- A parent that is not an epic.
- A parent from another project.
- A parent on an epic. Epics do not nest.
- A type with the children capability on a card that has a parent.
- A type without the children capability on an epic that has children.

The epic page lists the children with their columns, and shows a count such as
"3/7 done". A child counts as done when it sits in a terminal column. The child
page names its parent.

### Lanes

Each epic in an open column gets a lane on the board, and an epic in Backlog
gets one too. A lane is a row across all
the columns, and the epic's children sit in their columns inside that row. The
lanes follow the order of their epics: by column, then by rank. The last row,
**Other cards**, holds every card that is in no lane.

Each lane repeats the column headers, and each count shows the cards of that
lane only. **Other cards** holds the Add card links and the link to the
finished cards.

An epic lane has a maximum height, and each of its cells scrolls its own cards.
**Other cards** takes the height that the epic lanes leave, and each of its
columns scrolls on its own. When the epic lanes need more height than the page
has, the board scrolls down.

The lane header shows the epic number, a progress bar and the "3/7 done" count
above the epic title. A collapse button and a lane toggle sit to its left. An epic
with its lane on shows as the lane header only, not as a card in its column.

A new child of an epic moves from Backlog to Next at once. A person parks a
child by moving it back to Backlog, and a parked child never starts by itself.
An epic with children in Backlog shows an **Up next** deck at the right end of
its lane header, with a count such as "3 in Backlog". The deck holds the
parked children. It is a pile of those children in rank order. Hover over it or focus it, and it fans out to
show the cards. When the deck holds more cards than the fan shows, a "+N more"
tile takes the first place of the fan, under the pointer.
The tile opens the Backlog page filtered to the epic.

Drag a card out of the deck into a column to move it there. Drop a card on the
deck to send it to the end of Backlog as a child of that epic. While you drag a
card, a dashed outline marks each deck and the **Backlog** button that takes it.

The collapse button folds the lane into a slim bar with the epic number, its
title and its progress. The bar shows no deck, no lane toggle and no cards.
Your browser remembers the lanes you collapse, for each project. Another
browser shows every lane open.

The lane toggle turns the lane of that epic off or on. The epic page has the same
toggle, and an agent sets `laneEnabled` through MCP. The setting belongs to the
epic, so every browser sees it. With the lane off, the epic shows as a card with
its count, and its children show in **Other cards**. Each of them carries a tag
such as "↑ #214" that names its epic.

A board with no lane shows its columns only, as it did before epics.

You can drag a card into any lane. A drop in the lane of another epic makes that
epic the parent of the card. A drop in **Other cards** removes the parent. A drop
inside the same lane keeps the parent, so a child whose lane is off keeps its
epic when it moves inside **Other cards**. The column changes as it does on a
board with no lanes, and the card lands where you drop it.

An epic cannot go into a lane, because an epic has no parent. If the board
refuses a change of parent, it shows why and keeps the card where it was.

### When an epic is done

Loupe moves an epic on its own:

- When the last open child moves to a terminal column, the epic moves to the
  first terminal column of the board. If the epic links an open pull request,
  it moves to the `in-review` column instead and waits there. While the
  breakdown of the epic runs, the epic stays in the `implementation` column.
- When a child of a done epic or of an epic in `in-review` leaves the terminal
  column, or an open card joins such an epic, the epic moves back to the
  `implementation` column.
- When a child with a parent waits in Next and its last blocker
  moves to a terminal column, the child moves to the `implementation` column.
  The child waits until its epic sits in `implementation`, and while a run of
  its epic is open. When the epic enters `implementation`, or the run ends, the
  epic evaluates its children again, and a child with no open blocker moves.

A board with no `implementation` column skips the moves back. An epic with no
children never moves on its own.

A manual move of an epic to a terminal column is refused while a child is open.
The message names the open children. Move them to a terminal column first.

An epic with children cannot be deleted. Delete the children, or remove them
from the epic, first.

When an epic is done, its lane goes away. The epic shows in its terminal column
as one card with its count, and its children leave the board. The children stay
on the epic page, on the history page of their column, and in the MCP tools.

### The epic pull request

An epic can link its own pull request, which carries the merged work of its
children. Loupe counts a linked pull request as open until it is merged or
closed. A link that Loupe never read also counts as open.

While a linked pull request is open, the epic does not close when its last
child finishes. It waits in the `in-review` column. The merge of the pull
request then moves the epic to the first terminal column, as for any card. A
board with no `in-review` column, or a terminal one, closes the epic at once.

When each linked pull request is closed and none merged, the epic does not
close when its last child finishes. It stays where it is, as for any card.

For a repository connected through the GitHub App, Loupe also changes the
pull request, when the epic writes are on in [Automation](#automation):

- When the epic enters `in-review`, Loupe marks its linked pull requests ready
  for review.
- When the epic goes back to `implementation`, Loupe converts them to draft.
- When a person or an agent moves the epic to the Backlog, Loupe closes its
  open linked pull requests.

When you close the epic pull request on GitHub and none merged, the epic stays
where it is, as for any card.

### The epic branch

A repository profile can give each epic its own branch. Its `.loupe/lifecycle.md`
file then holds an `Epics` section. Under that profile, the breakdown of epic
number n pushes a branch `epic/<n>` from `main`. It pushes the branch only when
no child of the epic links a pull request yet.

Each child of the epic starts from `epic/<n>`, and its pull request targets
`epic/<n>`. A child that waits on blockers starts when they are done, so its
branch already holds their code. Before a merge, the merge stage updates the
child branch when it is behind `epic/<n>`. When the required checks pass, the
stage squash-merges the child into `epic/<n>`. That merge needs no approval.
GitHub has no rule for `epic/<n>`, so the stage checks the child against the
required checks of `main`, by name.

A merged child moves to `done` as any card does. Its code is on `epic/<n>`, and
not yet on `main`. The epic card stays open until its own pull request merges.

After the first child merge, the merge stage opens a draft pull request from
`epic/<n>` to `main` and links it to the epic. GitHub refuses a pull request
with no changes, so the stage cannot open it earlier. From then on, the epic
pull request behaves as the section above says. You approve it once, and the
merge stage squash-merges it into `main`.

A profile can also name an epic preview, a local copy of the app that runs the
code of `epic/<n>`. After each child merge, the merge stage creates or refreshes
the preview. It then copies the preview links of the child into the epic pull
request, each with the result of its check. A link reads "not proved" when the
data the child seeded for it is missing from the preview. It reads "not minted",
with a path and no link, when the account of the link is missing from the
preview.

When the epic pull request falls behind `main`, Loupe merges `main` into
`epic/<n>`, as for any pull request. Your approval covers that merge. A fix
round on the epic pull request pushes to `epic/<n>` directly. An open child
then falls behind `epic/<n>`, and the merge stage updates it before its merge.

An epic with one child can close before the stage links its pull request. The
merge stage then records a block on the epic card.

An epic whose breakdown ran before its profile had an `Epics` section has no
`epic/<n>` branch. The same applies to an epic with no `epic/<n>` branch and a
child that already links a pull request. Its children keep their pull requests
to `main`.

## What a card holds

| Field | What it is |
|---|---|
| Number | A short number, counting from 1, unique inside the project. |
| Title | Plain text, up to 255 characters. Loupe trims it and refuses a blank one. |
| Body | Markdown. It says what the card asks for. |
| Type | A type that the workflow template declares. `board_columns` lists them. |
| Status | The column the card sits in. The tools report the column's slug. |
| Reporter | `human`, `agent` or `reviewer`. It records who raised the card. |
| Source | Where the card came from: a person, the site review widget, a worker run, an agent outside a run, or Loupe. It never changes. |
| Pull requests | Any number of links, each with the last state Loupe read. See below. |
| Linked cards | Other cards of the project, each with a kind. See [Cards linked to a card](#cards-linked-to-a-card). |

A card also carries the moment it was created and the moment it last changed. A
card in a terminal column carries its completion time as well.

The site-review widget gives the default type of the workflow template to each
note card and review card it creates, and the source site review widget.

The source is set once, when the card is created. A card made by `card_create`
through a worker run records that run and the card of the run. A card made by an
agent with no worker run records the source agent. A card made from a ticked
readiness proposal records the source Loupe. The card page and the card lists
show the source as a badge.

The reporter never changes. `card_update` refuses that field, because it answers
who first raised the card rather than who touched it last. An MCP request
authenticates as the project owner, so the tools cannot tell an agent's own card
from one a person dictated. An agent writing down what a person asked for passes
`human` at creation.

An MCP caller may claim `human` or `agent` only. `card_create` refuses
`reviewer`, because the site-review widget owns that value. A filter is the
other way round: `card_list` matches all three, so the widget's cards stay
readable.

The field was called `origin` until this release. `card_create` still accepts
`origin` for one release, so an agent written against the old name keeps
working. Move to `reporter`. When a call sends both, `reporter` wins.

### The number and the id

The number is the handle a person uses. Say "card 42" in conversation, in a pull
request body, or in a branch name. It counts from 1 inside one project, so two
projects each have a card 1.

`cardId` is the card's UUID. `card_get` and `card_update` take either `cardId`
or `number`. Send exactly one of them, because a call with both or with neither
is refused. A number resolves only inside the project that the MCP connection is
bound to. An unknown number gives the error "This project has no card 42."

The other tools take no number. `card_create`, `card_list` and `card_search`
report both `cardId` and `number` in what they return. The card page URL still
takes the UUID alone.

## Pull request links

A card links to any number of pull requests. Nothing about a link is
GitHub-specific. Loupe stores the URL as you give it, up to 512 characters, and
puts whatever a parser could read beside it.

Loupe ships one parser, and it reads a GitHub pull request URL. It accepts the
host `github.com` or `www.github.com`, in any case, with the path
`/<owner>/<repo>/pull/<number>`. The scheme, a trailing slash and a query string
make no difference. Such a URL is stored with the forge `github`, the
`owner/repo` pair and the number.

Every other URL is stored with the forge `other`, and with no repository and no
number. That covers a URL from another forge, a self-hosted one, and one that
matches no shape Loupe knows.

Loupe refuses no URL for its shape. A link it cannot read is still the link a
reviewer wants on the card. Length is the one limit. A URL longer than 512
characters does not fit the column, so Loupe refuses the whole call. The form
shows the error on the pull request links field, and an MCP tool answers with
the limit.

A short enough URL can still name a repository or a number too large to store.
Loupe keeps the link and the forge, and leaves the repository and the number
empty.

Two identical URLs in one call are stored once, a blank entry is dropped, and
the links read back in the order they were added.

### What GitHub tells a card

The project owner connects the project's GitHub repositories on the Connections
tab. [Projects](projects.md#repositories) describes how. After that, a merge, a
review or a check result in a connected repository reaches each card of that
project which links the pull request. Your agent receives it as an event.

A card in another project gets nothing, even when it links the same pull
request.

For a repository connected through the GitHub App, Loupe reads the pull request
again after each delivery. It also tells the card about a conflict, a branch
behind its base, and a pull request closed without a merge. For a repository
connected through a webhook, Loupe learns only what GitHub sends.

Loupe stores the state it reads, and the card page, the board and `card_get`
show that stored state. Only a repository connected through the GitHub App gets
a state. Loupe also reads each open pull request again about every ten minutes,
in case GitHub did not send a delivery. A pull request in a repository connected
through a webhook, or in no connected repository, shows **Not reported**.
[Forge webhooks](../extending/forge-webhooks.md#one-vocabulary-for-every-forge)
lists each event.

The workflow of the board reads these states. It moves the card, asks a bridge
for a fix, or merges, when a rule of its template says so. A card in a terminal
column moves only when a rule of the template moves it.
[Workflows](workflows.md) lists the rules of the Lifecycle and Simple
templates. For a pull request that Loupe cannot read, move the card yourself,
or have your agent move it with `card_update`.

### Automation

The owner sets how the workflow acts on pull requests on the **Automation** tab
of the project settings, beside **Board columns**. Only a repository connected
through the GitHub App gets a write from Loupe. Each write is off until the owner
turns it on. With a write off, the workflow asks a bridge for the work instead,
as its template says.

| Setting | Default | Does |
|---|---|---|
| **Run the workflow of the board** | on | When off, the workflow moves no card and asks for no work on this board. Loupe still records the pull request facts |
| **Comment on the pull request when a fix run is queued** | off | When on, Loupe posts a comment on the pull request each time a bridge queues a fix run for it |
| **Comment on a pull request when new commits follow its approval** | off | When on, Loupe posts one comment for each new head that the approval does not cover |
| **Sync an approved pull request that is behind** | off | When on, Loupe updates the branch of an approved pull request that is behind its base. The GitHub App needs "Contents: read and write" |
| **Merge a pull request when the workflow asks** | off | When on, Loupe merges a pull request when the workflow of the board asks for it. The GitHub App needs "Contents: read and write" |
| **Change the base of a pull request when the workflow asks** | off | When on, Loupe changes the base branch of a pull request when the workflow of the board asks for it. The GitHub App needs "Pull requests: read and write" |
| **Switch an epic pull request between draft and ready when the workflow asks** | off | When on, Loupe marks the pull request of an epic as a draft in implementation, and as ready in review. The GitHub App needs "Pull requests: read and write" |
| **Close the pull requests of an epic when the workflow asks** | off | When on, Loupe closes the pull requests of an epic that moves back to the Backlog. The GitHub App needs "Pull requests: read and write" |
| **Open the epic pull request** | off | When on, Loupe opens a draft pull request from the epic branch to the default branch after the first child merges into the epic branch, and links it to the epic. The GitHub App needs "Pull requests: read and write" |
| **Post a widget verdict as a review on GitHub** | off | When on, a verdict that a reviewer sends from the site-review widget becomes a review on the pull requests of the card, under the reviewer's own GitHub account. Loupe stores the verdict and its notes with this setting off or on |
| **Keep a "Loupe site review" check on pull requests** | off | When on, Loupe posts a check named "Loupe site review" on each open pull request of a managed card. The check fails while open site-review notes remain on any card of the project that links the pull request. The GitHub App needs "Checks: read and write" |
| **Ask an agent to review each pull request** | off | When on, Loupe posts the check `loupe/agent-review` for each review that a review worker sends. Each finding shows as a note on its lines. No workflow rule asks for the review yet. The GitHub App needs "Checks: read and write" |
| **Findings that fail the agent review check** | Important | The finding severities that make the agent review check fail. Select one or more of Important, Nit and Pre-existing. Findings of other severities show as notes only |
| **Stuck delay in minutes** | 15 | The minutes a pull request may stay ready to merge before its card shows **Stuck**, from 1 to 1440 |
| **Epic branch pattern** | `epic/{number}` | The branch that the breakdown pushes for an epic. `{number}` stands for the epic card number. A child pull request into this branch merges into the epic. Leave it empty when the project uses no epic branches |

The draft and ready switch and the close write were on for each board whose automation was on before the
workflow engine, so the epic flow kept working.

The comment gives the reason for the fix and the failed checks. It also gives
a link to the card. The card page lists the runs. A comment that fails never
holds the run.

Loupe retries a comment 3 times when it fails for a passing reason, such as a
GitHub server error. When GitHub limits the rate, Loupe waits as long as
GitHub asks. A retry never posts a second comment for the same run. Loupe does
not retry a comment that GitHub refuses, such as for a missing permission. Fix
the cause, and the next queued fix run posts a new comment.

After the last failure, the tab shows the failure, its pull request and its
cause. The tab hides it when a later comment posts, or when you turn the
setting off. The GitHub App must
have Pull requests: read and write. The comment needs a bridge that reports
the event that queued a run. An older bridge sends none, so no comment posts.

An approval that does not cover the newest commit starts no merge. GitHub says
which commit the approval covers. When the approval comment setting is on, Loupe posts
one comment on the pull request, such as "Not merged: commit `abc1234` came
after your approval. Approve the new head to merge." Each head gets one
comment at most. A push of another commit gets a new comment. The setting needs
**Run the workflow of the board** on, and the GitHub App must have Pull requests:
read and write. A comment that fails retries like a fix run comment. The tab
does not show its failure.

The sync setting keeps approved work up to date with its base, so it can merge.
Loupe syncs one pull request of the project at a time. It picks the pull
request with the oldest approval, and the lower number breaks a tie. While an
approved pull request is up to date, or a sync of it runs, no other pull request
syncs. A sync that does not finish in ten minutes counts as failed.

Loupe syncs only a pull request that it reads as behind its base. That happens
only when the rules of the base branch require a branch to be up to date before
it merges. On another base, a pull request that is behind can merge as it is, so
nothing syncs.

A pull request counts as approved when a person with write access approved its
current head. An approval of a head that Loupe synced still counts, so a sync
needs no new review. The exception is a base branch whose rules dismiss stale
approvals on a push. GitHub then removes the approval when Loupe syncs, and the
pull request shows **Waits for an approval** until a person approves it again.
A request for changes removes the pull request from
the line. Loupe only updates the branch, and it never merges. The GitHub App
must have Contents: read and write. See
[Forge webhooks](../extending/forge-webhooks.md).

The Lifecycle template asks for a fix when the required checks fail, when the
pull request conflicts with its base, and when a reviewer requests changes. A
card gets 3 fix rounds at most, and then the workflow pauses it. See
[Workflows](workflows.md).

## The MCP tools

An agent drives the board through the MCP endpoint. See
[The MCP endpoint](mcp.md) for the token and the client setup.

| Tool | Arguments |
|---|---|
| `board_columns` | None. It also returns the card types of the project. |
| `card_create` | `title`, `body` and `type` are required. `status`, `reporter`, `pullRequestUrls`, `documentIds` and `relatedCards` are optional. `origin` is the old name for `reporter` and is deprecated. |
| `card_list` | `status`, `type` and `reporter`, each optional, each a filter. `page`, `perPage` and `full` are optional as well. |
| `card_search` | `query` is required. `page` and `perPage` are optional. |
| `card_get` | Exactly one of `cardId` and `number`. |
| `card_get_history` | Exactly one of `cardId` and `number`. `page` and `perPage` are optional. |
| `card_update` | Exactly one of `cardId` and `number` is required. `title`, `body`, `type`, `status`, `pullRequestUrls`, `documentIds` and `relatedCards` are optional. |
| `card_run_open` | `sessionId`, `name` and exactly one of `cardId` and `number` are required. `status` is optional. |
| `card_run_close` | `sessionId` and exactly one of `cardId` and `number` are required. |
| `column_create` | `label` is required. |
| `column_update` | `slug` is required. `label` and `terminal` are optional. |
| `column_reorder` | `order` is required: the slugs of every column except Backlog, in the new order. |
| `column_delete` | `slug` is required. `targetColumn` is required when the column holds cards. |
| `automation_settings_update` | Every argument is optional. Each one is a setting of **Automation**, such as `enabled`, `syncBehind`, `openEpicPullRequests` or `epicBranchPattern`. |

`board_columns` lists the columns of the board in board order. Each entry
carries `slug`, `label`, `terminal`, `default` and `backlog`. The Backlog row
has `default` and `backlog` both true. `card_list` returns the same
list in `columns`, beside its cards. The response also holds `types` and
`defaultType`: the card types that the workflow template declares, each with a
`key`, a `label` and the capabilities `children` and `lane`, and the type of a new
card. The `type` argument of the card tools takes a key, and a key that the
template does not declare is refused.

`column_create`, `column_update`, `column_reorder` and `column_delete` change
the columns, as **Board settings** does. They refuse the changes that Board
settings refuses, and the error says what the agent can fix. A setting that
`automation_settings_update` omits keeps its value. An empty `epicBranchPattern`
turns epic branches off. The call refuses a pattern that is not a branch name
with `{number}` exactly once, and then it saves nothing.

`card_run_open` and `card_run_close` record an interactive session on a card.
See [Interactive sessions](worker-runs.md#interactive-sessions).

A move that an agent makes through the MCP names its worker run in the card
history. This works when the `loupe` CLI sends the session of the agent in the
`X-Loupe-Session` header. A `card_run_open` call that moves the card names the
run it opens.

`status` takes a column slug on `card_create`, `card_update` and `card_list`. An
unknown slug is refused. The error lists the slugs the board has, such as
`Unknown status "doing". Use one of: backlog, next, in-progress, done.`

A write to a column that was deleted after your read is also refused. That error
says "That column no longer exists on this board. Name another column."

`card_create` with no `status` puts the card in Backlog. An agent
finishes a card by moving it to a terminal column.

`card_list` reads the whole board when you give it no filter. A column that is
not terminal reads in board order, by rank. A terminal column reads newest
completion first.

It answers one page at a time. `perPage` holds 50 cards by default and 100 at
most, and `page` counts from 1. Both are clamped into range rather than refused,
so a page past the end reads as an empty list. The answer carries `page`,
`perPage`, `total` and `hasMore`. `total` counts every card the filters match,
not the cards on the page, so keep reading while `hasMore` is true.

Each row is a summary: `cardId`, `number`, `title`, `type`, `status`,
`reporter`, `parentCardId` and `updatedAt`. Pass `full` to get the Markdown body, the pull
request and document links, the full feedback items, and `relatedCards` as well. A full page
is much larger, so read the board as summaries and call `card_get` for the card
you want.

`card_search` answers "is there already a card about this?" without reading the
whole board. It searches the title and the body of every card in the project,
done ones included, because a topic is often named only in a body and "yes, and
it is already done" is a true answer.

Matching is by word, not by substring, and words are stemmed, so `paging` finds
`pages`. Quote a phrase to require it, put `-` in front of a word to exclude it,
and write `or` between two words to accept either. A card whose title carries
the word outranks one that carries it only in the body.

It pages the same way `card_list` does: `perPage` holds 25 rows by default and
100 at most, and the answer carries `page`, `perPage`, `total` and `hasMore`. A
row is the same summary `card_list` returns, so call `card_get` for a body.

`card_get` returns one card with its full Markdown body, every pull request
linked to it, and all of its feedback, under `siteReviewComments`. Use a card id that
`card_list`, `card_search` or `card_create` gave you, or the card number.

Each entry of `pullRequests` carries `state`, the last state Loupe read:
`state`, `draft`, `checks`, `failedChecks`, `mergeability`, `review`,
`readyToMerge` and `refreshedAt`. It is null when Loupe holds no reading, such
as for a link it cannot parse or a pull request it never read. The card page
shows a pull request that Loupe never read as **Not reported**. `card_update`,
`card_create`, `card_run_open` and `card_list` with `full` return the same
`state` key. A `card_list` row without `full` does not carry it.

`review` is `approved`, `approval-outdated`, `changes-requested`, `required` or
`none`. `approval-outdated` means that the approval does not cover the newest
commit. While a pull request has an approval, `readyToMerge` stays false until
an approval covers the newest commit.

Every card row carries `state`. It is null for a card in no state and for a card
in a terminal column. Otherwise it holds `kind` (`stuck`, `needs-you`, `working`
or `waiting`), `code`, and `since`. `code` names the reason, such as `paused`,
`run-stopped`, `checks-failed`, `conflicting`, `ready-not-merged`,
`document-in-review`, `waits-for-approval`, `open-question`, `run-open`,
`work-requested`, `forge-request-pending` or `held-by-blocker`. `since` is the
time that reason began, or null when Loupe stores none. `card_get`,
`card_list` with `full`, `card_create`, `card_update` and `card_run_open` also
return `reason`, one sentence in English, and `others`, the other reasons that
apply, each with `kind`, `code`, `reason` and `since`. The summary row of
`card_list` and `card_search` carries `kind`, `code` and `since` alone. Filter
the rows of one `card_list` page on `state.kind` to find the stuck cards.

`card_get` also returns `relatedCards`, the cards linked to this one. Each entry
carries `cardId`, `number`, `title`, `status` and `kind`, where `kind` is how
this card reads the link. `card_search` does not carry `relatedCards`.

`card_get_history` reads what happened to one card, newest first. It pages the
same way `card_list` does, with 50 events by default and 100 at most. Each event
carries `kind`, `occurredAt` and `actor`.

`kind` is `created`, `moved`, `fix-requested`, `stopped`, `ready-to-merge`, `synced` or
`run-finished`. `actor` carries `kind` and `name`. Its `kind` is `human`,
`agent`, `reviewer` or `system`. `name` is the current name of the person
behind the event. It is null when there is no person, such as for a deleted
account or the app itself.

A `moved` event sets `from` and `to`, and a `created` event sets `to` alone.
Each is a column with `id`, `slug` and `label`. `cause` says why the app moved
the card on its own, such as a merged pull request. An action of the
[automation](#automation) sets `pullRequest`. A `fix-requested` or `stopped`
event also sets `reason`, such as `conflict`. A `run-finished`
event carries the stored record of the run in `run`. A key that does not apply
is null.

The history starts empty. A change made before Loupe began to record history
has no event.

## Cards raised from the review widget

Every note from the [site-review widget](site-review.md) lands on a card. The
reviewer chooses once where notes go: a new card for each note, one card for
the whole review, or a new card for each note under an epic. The widget can
pick an open card or epic, and it can create one.

A card raised that way records its reporter as **reviewer**. That says the app
could not name who raised it, because the widget authenticates a project and
never a person. A note card and a review card have the default type of the workflow
template.

Such a card always lands in Backlog, and carries no pull request
link. The widget offers neither, so a page visitor cannot file work straight
into a column. The widget refuses a card or an epic in a terminal column.

## Documents on a card

A card links to any number of documents in the same project, and the card page
lists them with a link through to each review. Pass `documentIds` to
`card_create` or `card_update`.

An id that names no document of the project is **refused**, which is where this
differs from a pull request link. A pull request URL is kept as given, because a
self-hosted forge is a legitimate answer nothing can check. A document id can be
checked, so a wrong one is an error rather than a stored string.

`documentIds` follows the same omit-versus-empty rule as `pullRequestUrls`: omit
it and the links stay, send an empty list and every one is removed.

The link is one-way. A document does not list the cards that point at it,
because the module boundary runs one way: Board may read Review, and Review must
not learn that cards exist.

### An approval moves a card

The workflow of the board decides what an approval does. In the Lifecycle
template, an approved product document, with the tag `product-design`, moves the
card from Product design to Tech design. An approved tech design, with the tag
`tech-design`, moves the card from Tech design to Implementation, once the card has no
open blocker. A blocker is open while it sits in a column that is not terminal,
and only a `blocks` link counts. The Workflow panel shows what the card waits
for. See [Workflows](workflows.md).

## Cards linked to a card

A card links to other cards of the same project. From one card, each link reads
as one of three kinds:

| Kind | Label | Meaning |
|---|---|---|
| `relates-to` | Related to | The two cards concern each other. |
| `blocks` | Blocks | This card must finish before the other card can. |
| `blocked-by` | Blocked by | The other card must finish before this card can. |

Both cards show the link. A `relates-to` link reads the same from both ends. A
`blocks` link from card A to card B reads `blocked-by` from card B. A pair of
cards holds one link, so card A cannot both block and relate to card B.

Pass `relatedCards` to `card_create` or `card_update`. Each entry takes a
`cardId` and a `kind`, and `kind` defaults to `relates-to`. Loupe ignores any
other key, so the `relatedCards` of `card_get` can go back unchanged.

`relatedCards` follows the omit-versus-empty rule of `documentIds`. Omit it and
the links stay. Send `[]` and every link is removed. Send a list and it replaces
the whole set, so send every link you want to keep.

Either end of a link governs it. A write on card B replaces every link that
touches card B, and that includes a link written from card A. The writer of
card A gets no notice. Read the card with `card_get` before you write its links,
or you can remove a link that another agent added.

Loupe refuses the whole call, and changes nothing, for each of these:

- A link from a card to itself.
- A `cardId` that names no card of this project. A card of another project
  reads as unknown.
- The same card twice in one set.
- A `kind` other than the three above.

Deleting a card removes each link that touches it. Deleting a project removes
every link of its board.

An open `blocked-by` card keeps an approved card in its stage column. The card
moves on when its last blocker reaches a terminal column, or when its last
blocking link goes away. See [An approval moves a card](#an-approval-moves-a-card).

## Review feedback on a card

Each site-review note is feedback on one card. The widget writes the note and
its card link in one request, so a new note never exists without its card. A
preview page can lock the widget to one card, through `data-context` and
`SITE_REVIEW_WIDGET_CONTEXT`. See [Site review](site-review.md#a-preview-page-locks-the-card).

The card page's **Feedback** tab lists each note with its page, its elements
and its status. A note moves through three states:

| Status | Who sets it |
|---|---|
| Pending | The reviewer saves the note. |
| Addressed | An agent marks it with `feedback_mark_addressed`. |
| Resolved | A person presses **Resolve** on the Feedback tab or in the widget, or the card finishes. |

A card finishes when it moves into a terminal column from a column that is not
terminal. Every pending or addressed note on it then becomes resolved. A column
delete that moves cards from an open column into a terminal column does the same,
and so does **Mark as terminal** on a column that holds cards. A move between two
terminal columns resolves nothing new. A move back out of a terminal column
leaves the notes resolved. **Reopen** on the Feedback tab makes a resolved note
pending again.

The board screen shows on each card how many notes still wait on it.

### A verdict from the widget

A reviewer signs in to the widget and sends a verdict on a card: Approve,
Request changes or Comment. The verdict keeps the message and a copy of the
pending notes of the card at that time. The copy does not follow a later edit
of a note. The reviewer picks the open GitHub pull requests of the card that
the verdict goes to. The card history records the verdict, with the name of
the reviewer.

A verdict changes nothing on GitHub by itself. The
[rules the app adds](workflows.md#rules-the-app-adds) write to the pull
requests, and only while the matching setting on the **Automation** tab is on:

- With **Post a widget verdict as a review on GitHub** on, the verdict becomes
  a review under the reviewer's own GitHub account. On the reviewer's own pull
  request it becomes a comment. With the setting off, the verdict stays on the
  card and no review goes out. Each review opens with a line that names the
  Loupe site review and links to the card.
- With **Keep a "Loupe site review" check on pull requests** on, the check
  fails while a pending note that a verdict carried remains. The check also
  runs when nobody sends a verdict. It is green until a verdict carries a note.
  When two cards link one pull request, the check counts the pending notes of
  both cards. When you switch the setting off, Loupe turns each failed check on
  an open pull request to neutral, so it no longer blocks a merge.

Before the reviewer sends, the widget lists the writes that the verdict will
start. The list follows the rules and the settings of the project. It does not
list the fix round that a request for changes on GitHub starts later.

The widget shows the state of each pull request after the send. When the
connection to GitHub has expired, the review is refused with the reason
`connection-expired`. The reviewer connects again from the widget, and Loupe
then sends the refused reviews of that reviewer on cards that are not in a
terminal column.

`card_update` reads an omitted field as "leave it alone". `pullRequestUrls` is
the one field where an omitted list and an empty list differ. Omit it and the
links stay. Send `[]` and every link is removed.

### A check from an agent review

A review worker sends its findings with the `agent_review_submit` MCP tool. Each
finding names a file, a range of lines and a severity. With **Ask an agent to
review each pull request** on, Loupe posts a check named `loupe/agent-review` on
the commit that the worker reviewed. The check puts one note beside the lines of
each finding. An important finding shows as a failure, a nit as a warning, and a
pre-existing finding as a notice. The check fails when a finding has a severity
from **Findings that fail the agent review check**. The Lifecycle template asks
for the review. See [Agent review](workflows.md#agent-review).

### A person deletes a card, an agent does not

The board offers no `card_delete`, and nothing on the MCP surface deletes a
card. An agent finishes a card by moving it to a terminal column, which keeps
the record of the work.

A person deletes a card from the card page. See
[The card page](#the-card-page). The delete removes the card's feedback too.

The widget deletes a card in one case. A reviewer deletes a pending note, and
the widget deletes the note's card too when all of these are true:

- The note created the card.
- The card is still in Backlog.
- The card holds no other feedback.
- The card keeps the default type and the title that the note gave it.
- The card body is empty.
- The card has no pull request, no document and no link to or from another card.

## Deleting a project

Deleting a project deletes its board with it, cards, pull request links, card
links and workflow data included.
