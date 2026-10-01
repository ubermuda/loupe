---
title: "The project board"
description: "The columns each board has and how an owner changes them, how cards are ordered, the board screen a person drags cards on, and the MCP tools an agent drives them with."
---

Every project has one board, and the board holds cards. A card describes one
piece of work: what it asks for, how urgent it is, and which column it sits in.
A person works the board on its own screen. An agent reads and writes the same
board through the MCP endpoint.

The board is behind the `board.enabled` feature flag, and the flag ships on.
[Site review](site-review.md) writes each note to a card, so it needs the board.
See [Turning the board off](#turning-the-board-off).

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
The tools still list Backlog, so an agent or a bridge rule can name `backlog`.

A board from an earlier release had a default column instead, which the owner
could rename. The upgrade turns that column into Backlog, with the label
Backlog and the slug `backlog`, so a custom name is lost. When its old slug was
not `backlog`, a bridge rule or a prompt that named the old slug stops
matching. When another column held the
slug `backlog`, the upgrade moves its cards to the end of Backlog, in rank
order, and deletes that column.

### Terminal columns

A terminal column holds finished work. A card that enters one gets a completion
time. A board has at least one terminal column, and it can have more. See
[Terminal columns behave differently](#terminal-columns-behave-differently).

### Change the columns

Only the project owner changes columns, in **Board settings**. The board itself
has no column controls, and its columns cannot be dragged. No MCP tool and no
API route writes a column.
Another reader of the board sees no column controls.

Open **Board settings** to manage columns beside the other project settings.
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

A rename that changes the slug breaks every outside reference to the old slug.
Loupe cannot see these references, so it cannot warn you about them:

- A bridge rule whose `to` or `from` names the old slug. The bridge checks
  slugs at start and at each reload. The running bridge stops matching the
  rule, and a restart or a reload refuses the unknown slug. Put the new slug in
  `rules.yaml`, then run `loupe bridge reload`. See
  [Command-line bridge](../extending/cli-bridge.md).
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
writes no `board.card_moved` event, so no bridge rule on card moves fires.

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

The board screen shows the last 7 days of each terminal column, and a history
page carries the rest. `card_list` applies no such window. Every finished card
is on the board it pages through, however old it is.

## The board screen

The board is at **`/projects/<project>/board`**, and the project sidebar links
to it. The columns read side by side, in board order. Each card shows its
number, its title, its type, how many pull requests it links to, and how many
review comments still wait on it.
A card whose latest worker run gave up or is blocked also shows a warning. See
[A warning on the card](worker-runs.md#a-warning-on-the-card).

A card also shows a badge for each problem on its open pull requests.
**Checks failed** means that the checks of a linked pull request fail.
**Conflict** means that a linked pull request has a merge conflict.
**Automation blocked** means that the automation stopped asking for fixes on
the card. A card in a terminal column shows no such badge. A card and its row
in the **List** view show the same badges. The badges change when Loupe reads a
new state, with no reload. They come only from a state that Loupe read, so a
pull request that shows **Not reported** adds no badge.

Each card type and each column has a colour, and every page that names one uses
the same colour. The owner picks a column's colour from twelve in its
**Colour** setting. The colour stays with the column when the columns move.

The page header is one row: the title, the search, the card count, the
**Board** and **List** switch, a **Board settings** button with a gear icon,
and **Add card**. It wraps on a narrow screen, and it stays in place when the
cards scroll.

A second header row holds a **Backlog** button. The button shows how many cards
Backlog holds, and it opens the Backlog page. It also takes a dropped card.

Each column head shows the column colour, its label and its card count. Each
column scrolls its own cards, and the board scrolls sideways as one block.

Drag a card to move it. The whole card is the handle, and the grip on its left
says so. Where you drop the card decides what the move does.

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

Above the list, search the title and the body, and filter by type and by epic.
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

### Bridge rule health

Open Rules to read the project's reported handoffs. Each rule shows its event and its columns by their display names.
Search by rule name. The list updates as you type, or when you press Enter.
Matching ignores case. Clear restores the full list; the live-rule count always covers the project's complete report.
Searches with no matches show a different message from a project with no reported rules.
On narrow screens or with enlarged text, the search row scrolls to reveal each control as you press Tab or Shift+Tab.
This page remains read-only. Change rules in the bridge configuration.

A [command-line bridge](../extending/cli-bridge.md) can report the health of its
rules for each project it follows. A rule is dead when it can no longer match,
for example after its column was renamed or deleted. A dead rule starts no
agent.

When any bridge reports a dead rule, the board shows a banner above the columns.
The banner names each dead rule, the column slugs it watches, the reason the
bridge gave, the bridge that sent it, and when the report arrived. Only the
owner of the project sees it. The banner goes away when every bridge sends a
report with no dead rule. A bridge that stops for good leaves its last report,
and so its banner, in place. To clear such a report, send an empty report for
that bridge id, as the [bridge page](../extending/cli-bridge.md) describes. The
banner shows the first eight characters of the bridge id, and the full id is in
their tooltip.

The column dialog and the delete dialog in board settings warn before they save when a live rule
watches that column's slug. A rename changes the slug, and a delete removes it,
so the rule stops matching in both cases.

The Rules page also has a Hooks section. It shows one block for each of your
bridges whose heartbeat names the project, the latest heartbeat first. Each
block shows the last 12 characters of the bridge id, with the full id in the
tooltip, and the time of the last heartbeat. Under it, each
[hook package](../extending/bridge-hooks.md) of the bridge has one row for each
event it defines. A row looks like a rule row. It shows the package, its ref,
the event, the bridge and the time of the last run.

The chip of a row reads OK, Failed, Timed out or Not run yet. A failed or timed
out row shows the end of the hook's output, or the error when the hook could not
start. A bridge with no hook shows "No hook is installed." Each heartbeat
replaces the rows of its bridge, so a bridge that stops keeps its last list. A
`stop` run never reaches the page, because the bridge closes its send queue
before its `stop` hooks run.

### The card page

A card has its own page at **`/projects/<project>/board/cards/<card id>`**. The
card id is the UUID, not the number.

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
when one applies, and the review: **Approved**, **Changes requested** or
**Review required**. A merged or closed pull request shows **Merged** or
**Closed** alone. A link that Loupe never read shows **Not reported**, and a link
with no usable URL shows **Unavailable**. See
[What GitHub tells a card](#what-github-tells-a-card).

When the automation acted on the card, a line under the links says what it did
last, and when. It asked for a fix round, it stopped, or it marked the pull
request ready to merge. See [Automation](#automation). When the automation is
blocked, a notice gives the reason: the checks fail, the pull request has a
merge conflict, or a reviewer requested changes. Loupe clears the block and
resets the fix count when a person moves the card to another column, when the
checks pass, or when a reviewer approves or requests changes. A card in a
terminal column shows no block.

When an approval did not move the card because a blocker is open, the page says
**Approved, and waits for its blockers:** and links to each open blocker. See
[An approval waits for open blockers](#an-approval-waits-for-open-blockers).

The card page and the drawer update live, with no reload. A move of the card, a
change to one of its pull requests, an automation action or a change to a
worker run updates them. The open tab stays open. A form
with unsaved input keeps that input, and an open dialog holds the update until
it closes. Live updates need the same hub and flag as the board, see
[Live changes](#live-changes).

The page also lists up to five agent runs of the card that are still in
progress, with the rule that started each run, when it started and its state.
A run opens its details on the **Runs** tab of the Activity page. A finished
run leaves this list, and the **Run history** link shows it. A card with no
run in progress, no usage and no hold shows no runs section.
The project owner can stop a run in progress, or cancel a stop that still
waits for the bridge, from this list. Resume a finished run on the **Runs**
tab of the Activity page. See
[Stop, resume and cancel](worker-runs.md#stop-resume-and-cancel).

A stop of a run holds the card. The runs section then shows **Held: no worker
starts on this card until you resume one of its runs in Run history, or move
it.** No bridge starts a worker on a held card. These actions release the
hold:

- A person resumes one of the runs of the card.
- A person moves the card to another column. A move by an agent or by the
  automation keeps the hold, and so does a move inside the same column.
- A person cancels the stop while it still waits for the bridge. This releases
  only the hold that this stop wrote.
- A person deletes the column of the card, or the card.

The **History** tab lists what happened to the card, newest first, on a
timeline. A row says who created the card and in which column, and who moved
it from one column to another. When Loupe moved the card on its own, a line
under the row says why, for example after a pull request merged. An agent's
move inside a worker run names that run on the same line. The tab also
records when the automation asked for a fix or stopped, with the reason, and
when a pull request was ready to merge. A finished agent run shows its
rule, its duration and its result, and it links to the run while the run is
kept.

A row names a person by their full name. An agent's change reads **Agent for**
and the name of the person the agent works for. A change by the app reads
**Loupe**. A change from the site-review widget reads **A reviewer**, because
the widget does not name its visitor. A deleted account reads **A deleted
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

## Epics and lanes

An epic is a card of the type `epic`. It groups other cards, its children, so a
large feature can go to many small cards and still read as one piece of work.

### Parents

A card can have one epic as its parent. Set the parent in the **Parent epic**
field of the card form, or with `parentCardId` in the MCP tools. Loupe refuses
these changes:

- A parent that is not an epic.
- A parent from another project.
- A parent on an epic. Epics do not nest.
- The type `epic` on a card that has a parent.
- Another type on an epic that has children.

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

An epic with children in Backlog shows an **Up next** deck at the right end of
its lane header, with a count such as "3 in Backlog". The deck is a pile of
those children in rank order. Hover over it or focus it, and it fans out to
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
  first terminal column of the board.
- When a child of a done epic leaves the terminal column, or an open card joins a
  done epic, the epic moves back to the `implementation` column.
- When a child with a parent waits in Backlog and its last blocker
  moves to a terminal column, the child moves to the `implementation` column.

A board with no `implementation` column skips the moves back. An epic with no
children never moves on its own.

A manual move of an epic to a terminal column is refused while a child is open.
The message names the open children. Move them to a terminal column first.

An epic with children cannot be deleted. Delete the children, or remove them
from the epic, first.

When an epic is done, its lane goes away. The epic shows in its terminal column
as one card with its count, and its children leave the board. The children stay
on the epic page, on the history page of their column, and in the MCP tools.

## What a card holds

| Field | What it is |
|---|---|
| Number | A short number, counting from 1, unique inside the project. |
| Title | Plain text, up to 255 characters. Loupe trims it and refuses a blank one. |
| Body | Markdown. It says what the card asks for. |
| Type | One of `feature`, `bug`, `security`, `tooling`, `docs`, `idea`, `epic`, `site-review`. |
| Status | The column the card sits in. The tools report the column's slug. |
| Reporter | `human`, `agent` or `reviewer`. It records who raised the card. |
| Pull requests | Any number of links, each with the last state Loupe read. See below. |
| Automation | What the board automation did last on the card, and why it is blocked, if it is. See [Automation](#automation). |
| Linked cards | Other cards of the project, each with a kind. See [Cards linked to a card](#cards-linked-to-a-card). |

A card also carries the moment it was created and the moment it last changed. A
card in a terminal column carries its completion time as well.

The site-review widget gives the type `site-review` to each note card and review
card it creates. The board shows that type in teal. A person or an agent can
also set it.

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

For a repository connected through the GitHub App, Loupe also moves the card,
while the automation is on:

- When the required checks pass on an open pull request that is not a draft, a
  card in the `implementation` column moves to `in-review`. A draft whose
  checks passed moves the card when it is marked ready. A board with no
  `in-review` column, or a terminal one, skips this move.
- When the pull request merges or closes, and each pull request of the card is
  merged or closed with at least one merged, the card moves to the first
  terminal column. A link that Loupe never read, such as one on another forge,
  counts as open and holds the card back. An epic with an open child stays
  where it is.
- When each pull request of the card is closed and none merged, the card
  moves to the Backlog about ten minutes after the last close. Loupe checks the
  links again at that time. An open, merged or unread pull request cancels the
  move, so a reopen or a new open link keeps the card. A closed pull request
  linked in that time does not cancel it. A card that a person moves
  to a terminal column in that time stays there. An epic with an open child
  stays where it is. The move is a system move, so it starts a bridge rule that
  watches the Backlog.

The system makes these moves, and a card in a terminal column never moves. For
any other pull request, move the card yourself, or have your agent move it with
`card_update`.

### Automation

Loupe can also ask your agent to act on a pull request: to fix it, or to merge
it. It also moves the card on green checks and on a merge, as
[What GitHub tells a card](#what-github-tells-a-card) describes. The owner sets
this on the **Automation** tab of the project settings, beside **Board columns**. Only a repository connected through the GitHub App
gets these requests. A card in a terminal column never gets one.

| Setting | Default | Does |
|---|---|---|
| **Send fix and merge requests** | on | When off, Loupe sends no fix or merge request and moves no card. It still sends the pull request facts |
| **Merge strategy** | Worker | Worker sends a ready-to-merge event when a pull request can merge. Off sends none |
| **Fix strategy** | Fresh | Fresh starts a new worker for each fix. Resume asks the bridge to resume the last session of the card, and falls back to a new worker |
| **Loop limit** | 3 | The number of fix requests a card gets in a row, from 1 to 20 |
| **Comment on the pull request when a fix run is queued** | off | When on, Loupe posts a comment on the pull request each time a bridge queues a fix run for it |

The comment gives the reason for the fix and the failed checks. It also gives
the fix round against the loop limit, and a link to the card. The card page
lists the runs. A comment that fails never holds the run.

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

Loupe asks for a fix when the required checks fail, when the pull request
conflicts with its base, and when a reviewer requests changes. At the loop
limit, Loupe stops asking for the card. Passed checks, an approval, a change
request, or a move of the card by a person start the count again. A comment
review does not. A bridge rule decides what the agent does with each request. See
[Forge webhooks](../extending/forge-webhooks.md#the-loop-limit).

## The MCP tools

An agent drives the board through the MCP endpoint. See
[The MCP endpoint](mcp.md) for the token and the client setup.

| Tool | Arguments |
|---|---|
| `board_columns` | None. |
| `card_create` | `title`, `body` and `type` are required. `status`, `reporter`, `pullRequestUrls`, `documentIds` and `relatedCards` are optional. `origin` is the old name for `reporter` and is deprecated. |
| `card_list` | `status`, `type` and `reporter`, each optional, each a filter. `page`, `perPage` and `full` are optional as well. |
| `card_search` | `query` is required. `page` and `perPage` are optional. |
| `card_get` | Exactly one of `cardId` and `number`. |
| `card_get_history` | Exactly one of `cardId` and `number`. `page` and `perPage` are optional. |
| `card_update` | Exactly one of `cardId` and `number` is required. `title`, `body`, `type`, `status`, `pullRequestUrls`, `documentIds` and `relatedCards` are optional. |
| `card_run_open` | `sessionId`, `name` and exactly one of `cardId` and `number` are required. `status` is optional. |
| `card_run_close` | `sessionId` and exactly one of `cardId` and `number` are required. |

`board_columns` lists the columns of the board in board order. Each entry
carries `slug`, `label`, `terminal`, `default` and `backlog`. The Backlog row
has `default` and `backlog` both true. `card_list` returns the same
list in `columns`, beside its cards. The tools read columns and never write one.

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
shows a pull request that Loupe never read as **Not reported**. The card also
carries `automation`, with
`fixRounds`, `blockedReason`, `lastAction` and `lastActionAt`. It is null when
the automation never acted on the card. `card_update`, `card_create`,
`card_run_open` and `card_list` with `full` return the same two keys. A
`card_list` row without `full` carries neither.

`card_get` also returns `relatedCards`, the cards linked to this one. Each entry
carries `cardId`, `number`, `title`, `status` and `kind`, where `kind` is how
this card reads the link. `card_search` does not carry `relatedCards`.

`card_get` also returns `heldBy`, the open blocking cards that keep an approved
card in its stage column. Each entry carries `cardId`, `number`, `title` and
`status`. The list is empty when the card is not held. `card_create`,
`card_update` and `card_run_open` return it too, and `card_list` does not. See
[An approval waits for open blockers](#an-approval-waits-for-open-blockers).

`card_get_history` reads what happened to one card, newest first. It pages the
same way `card_list` does, with 50 events by default and 100 at most. Each event
carries `kind`, `occurredAt` and `actor`.

`kind` is `created`, `moved`, `fix-requested`, `stopped`, `ready-to-merge` or
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
never a person. A note card and a review card have the type `site-review`. An
epic has the type `epic`.

Such a card always lands in Backlog, and carries no pull request
link. The widget offers neither, so a page visitor cannot file work straight
into a column. The widget refuses a card or an epic in a terminal column.

All of this needs `board.enabled`. While the flag is off, the feedback endpoints
answer 409 `board_disabled`, and the widget says "Turn on the board to use site
review".

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

An approval of a stage document moves each linked card that sits in the column
the stage starts from. A product design is a document with the tag `product`,
and its approval moves a card from Product design to Tech design. A tech design
is a document with the tags `design` and `decisions`, and its approval moves a
card from Tech design to Implementation. A document with the tags of both stages
moves no card.

### An approval waits for open blockers

An approval does not move a card while a card that blocks it is open. A blocker
is open while it sits in a column that is not terminal. Only a `blocks` link
counts, and a `relates-to` link holds nothing. The approval stays on the
document, and the card stays in its column.

The card moves on when it has no open blocker left:

- The last open blocker moves into a terminal column.
- A person or an agent removes the last blocking link, from either card, or
  changes it to another kind or direction.
- A person deletes the last open blocker.

The card then moves to the column the stage leads to, as the app. It moves only
while it still sits in the stage column and its document is still approved. A
card that a person moved away since stays where it is.

The card page names the open blockers, and `card_get` lists them under `heldBy`.
The board tile and `card_list` do not show the hold. A move by a person or an
agent is never held, so a card with an open blocker still moves by hand.

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
blocking link goes away. See
[An approval waits for open blockers](#an-approval-waits-for-open-blockers).

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

`card_update` reads an omitted field as "leave it alone". `pullRequestUrls` is
the one field where an omitted list and an empty list differ. Omit it and the
links stay. Send `[]` and every link is removed.

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
- The card keeps the site review type and the title that the note gave it.
- The card body is empty.
- The card has no pull request, no document and no link to or from another card.

## Turning the board off

`board.enabled` ships on, because site review writes each note to a card. The
install wizard sets it on for a fresh install. On an instance that upgrades, a
database migration sets it on, and writes the row when it is missing. Run the
migrations as part of the upgrade.

An operator can switch it off at **`/admin/feature-flags`**. The change needs no
restart. See [The admin area](admin.md). While the flag is off:

- The `card_*` tools, `board_columns`, `feedback_list` and
  `feedback_mark_addressed` are absent from `tools/list` and from the project's
  Connect page. A client that holds an older tool list and calls one anyway gets
  a plain refusal.
- The widget refuses notes and says "Turn on the board to use site review".

A missing row reads as off. If the flags page does not list the flag, the
migration has not run. **`/admin/feature-flags/scan`** lists every flag the code
references that the database does not define, and it creates those rows on
request.

## Deleting a project

Deleting a project deletes its board with it, cards, pull request links, card
links and bridge rule reports included.
