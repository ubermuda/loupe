---
title: "The project board"
description: "The columns each board has and how an owner changes them, how cards are ordered, the board screen a person drags cards on, and the MCP tools an agent drives them with."
---

Every project has one board, and the board holds cards. A card describes one
piece of work: what it asks for, how urgent it is, and which column it sits in.
A person works the board on its own screen. An agent reads and writes the same
board through the MCP endpoint.

The board is behind the `board.enabled` feature flag, and the flag ships off. A
fresh install has no board until an operator switches it on. See
[Turning the board on](#turning-the-board-on).

## Columns

Each board has its own columns. A card sits in exactly one column. A person
sees the column's label, and the tools name the column by its slug.

A new board starts with these columns:

| Label | Slug | Flag |
|---|---|---|
| Backlog | `backlog` | default |
| Next | `next` | |
| In progress | `in-progress` | |
| Done | `done` | terminal |

A board keeps these columns until its owner changes them. A project that
existed before columns were configurable got the same columns.

### The default column

A card created with no column lands in the default column. That covers the
create form, which preselects it, a card raised from the review widget, and
`card_create` with no `status`.

A board has exactly one default column, and the default column is never
terminal.

### Terminal columns

A terminal column holds finished work. A card that enters one gets a completion
time. A board has at least one terminal column, and it can have more. See
[Terminal columns behave differently](#terminal-columns-behave-differently).

### Change the columns

Only the project owner changes columns, through the board or project settings.
No MCP tool and no API route writes a column.
Another reader of the board sees no column controls.

Open **Board settings** to manage columns beside the other project settings.
The **Board columns** section lists the columns in order. Each row has
**Move up** and **Move down** arrows, a gear button, and a delete button. The
gear opens a dialog with the column name, **Default for new cards**,
**A finishing point for completed work**, and **Colour**. **Save column** saves
them together, so one save can move the default flag to a terminal column and clear
its terminal flag. Each action returns to this section. The board header menus
remain available.

Reorder and configure changes check the state shown when the form opens.
If another editor changes that state first, Loupe refuses the stale change.
A refused configure keeps your draft in its dialog. Copy it before you reload.
A refused reorder shows the current order with an error message.

The owner adds a column with **Add a column** in board settings. A new column
is neither terminal nor the default. The dialog preselects a colour that no
other column on the board uses, and the owner can pick another before saving.
When every colour is in use, Loupe picks one at random. The owner drags a column header by its grip
to reorder the columns. Board settings shows a **Default** or **Terminal** badge
on a flagged column.

Each column header has a menu for the owner:

| Menu item | What it does | When the menu shows it |
|---|---|---|
| **Rename** | Opens the rename dialog. | Always. |
| **Move left**, **Move right** | Moves the column one place. | When a column is on that side. |
| **Mark as terminal** | Makes the column terminal. | On a column that is neither terminal nor the default. |
| **Unmark as terminal** | Makes the column not terminal. | On a terminal column, while the board has another one. |
| **Make the default for new cards** | Moves the default flag to this column. | On a column that is neither terminal nor the default. |
| **Delete column** | Opens the delete dialog. | On a column that is neither the default nor the last terminal column. |

The menu of the default column, and of the last terminal column, shows a note
instead of **Delete column**. Give another column that flag first.

A column that becomes terminal gives a completion time to each card in it that
has none. A column that stops being terminal ranks its cards by completion time,
then clears their completion times.

### Labels and slugs

A label holds at most 100 characters. The slug follows the label: Loupe
transliterates the label to ASCII, lowercases it, and joins the words with
hyphens. "In progress" gives `in-progress`, and "Café" gives `cafe`.

Loupe refuses a label when one of these is true:

- The label has no letter or digit that transliterates, such as an emoji alone.
- The slug already belongs to another column of the board. "Done" and "done!"
  both give `done`.
- The label is text the app uses internally, such as a translation key.

The rename dialog shows the new slug as you type, and it shows a refusal before
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

**Delete column** opens a dialog. The dialog for an empty column asks for a
confirmation only.

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
[Worker runs](worker-runs.md#a-warning-on-the-card).

Each card type and each column has a colour, and every page that names one uses
the same colour. The owner picks a column's colour from twelve in its
**Colour** setting. The colour stays with the column when the columns move.

Board settings and Add card stack when their labels need more space.

Drag a card to move it. The whole card is the handle, and the grip on its left
says so. Where you drop the card decides what the move does.

- Drop it inside its own column to change its rank in that column.
- Drop it in another column to change its column. The card takes the end of
  that column.

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

### Live changes

Every open board of the project shows a change as it happens, with no reload.

- When someone else adds, edits, moves or deletes a card, that card changes in
  place. The other cards, the scroll position and the filter stay.
- A card that someone else changed gets a short highlight. With reduced motion
  on, the highlight is a still outline.
- A card that you drag waits. The change shows when the drag ends.
- When the owner adds, renames, reorders, flags or deletes a column, the board
  changes in place. A drag in progress on another screen can then fail, and
  the card goes back.

When the connection to the server stops for about 5 seconds, the toolbar
shows **Live updates paused**. When the connection comes back, the board loads
again in place and catches up. The sign then goes away.

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

The rename and delete dialogs of a column warn before they save when a live rule
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

The page carries the full Markdown body, every pull request link, the review
feedback pointing at the card, and the times the card was created, last changed
and completed. Its Status field is a column control, which moves a card
with no drag. That is the way to move a card from a keyboard.

The page also lists the card's five latest agent runs, with the rule that
started each run, when it ran, how long it took and how it ended. A run opens
its details on the run history page.

A card with links to other cards shows a **Linked cards** table. Each row gives
the kind of link, the other card's number, its title and its column. A row opens
that card in the drawer. A card with no links shows no table. See
[Cards linked to a card](#cards-linked-to-a-card).

When the inbox is on, the page also lists the inbox items linked to the card,
and you can answer them there. See
[On a card page and a document page](inbox.md#on-a-card-page-and-a-document-page).

On the board, the Workshop, a document and the Site review page, a card opens
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

**Delete** asks for a confirmation first, then removes the card and
its links. A delete cannot be undone, and the number the card held is not
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

Each epic in an open column gets a lane on the board. A lane is a row across all
the columns, and the epic's children sit in their columns inside that row. The
lanes follow the order of their epics: by column, then by rank. The last row,
**Other cards**, holds every card that is in no lane.

Each lane repeats the column headers, and each count shows the cards of that
lane only. The first lane holds the column grips and menus, and keeps its
column headers when you collapse it. **Other cards**
holds the Add card links and the link to the finished cards.

The lane header shows the epic number, its title, the "3/7 done" count, a
collapse button and a lane toggle. An epic with its lane on shows as the lane
header only, not as a card in its column.

The collapse button hides the cards of the lane and keeps the header. Your
browser remembers the lanes you collapse, for each project. Another browser
shows every lane open.

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
- When a child with a parent waits in the default column and its last blocker
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
| Type | One of `feature`, `bug`, `security`, `tooling`, `docs`, `idea`, `epic`. |
| Status | The column the card sits in. The tools report the column's slug. |
| Reporter | `human`, `agent` or `reviewer`. It records who raised the card. |
| Pull requests | Any number of links. See below. |
| Linked cards | Other cards of the project, each with a kind. See [Cards linked to a card](#cards-linked-to-a-card). |

A card also carries the moment it was created and the moment it last changed. A
card in a terminal column carries its completion time as well.

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

Loupe does not ask GitHub about a pull request. It learns only what GitHub
sends. A card does not move by itself when its pull request merges. Move it to
a terminal column yourself, or have your agent move it with `card_update`.

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
| `card_update` | Exactly one of `cardId` and `number` is required. `title`, `body`, `type`, `status`, `pullRequestUrls`, `documentIds` and `relatedCards` are optional. |
| `card_run_open` | `sessionId`, `name` and exactly one of `cardId` and `number` are required. `status` is optional. |
| `card_run_close` | `sessionId` and exactly one of `cardId` and `number` are required. |

`board_columns` lists the columns of the board in board order. Each entry
carries `slug`, `label`, `terminal` and `default`. `card_list` returns the same
list in `columns`, beside its cards. The tools read columns and never write one.

`card_run_open` and `card_run_close` record an interactive session on a card.
See [Interactive sessions](worker-runs.md#interactive-sessions).

`status` takes a column slug on `card_create`, `card_update` and `card_list`. An
unknown slug is refused. The error lists the slugs the board has, such as
`Unknown status "doing". Use one of: backlog, next, in-progress, done.`

A write to a column that was deleted after your read is also refused. That error
says "That column no longer exists on this board. Name another column."

`card_create` with no `status` puts the card in the default column. An agent
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
request, document and site-review links, and `relatedCards` as well. A full page
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
linked to it, and every site-review comment pointing at it. Use a card id that
`card_list`, `card_search` or `card_create` gave you, or the card number.

`card_get` also returns `relatedCards`, the cards linked to this one. Each entry
carries `cardId`, `number`, `title`, `status` and `kind`, where `kind` is how
this card reads the link. `card_search` does not carry `relatedCards`.

## Cards raised from the review widget

A reviewer using the site-review widget can pick which card their comment
attaches to, and can create a card without leaving the page. A card raised that
way records its reporter as **reviewer**, which says the app could not name who
raised it: the widget authenticates a project, never a person.

Such a card always lands in the default column, and carries no pull request
link. The widget offers neither, so a page visitor cannot file work straight
into a column.

This is off unless the board is. Both the picker and the create control need
`board.enabled`, and the endpoints behind them answer 404 while it is off.
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

## Review feedback on a card

A site-review comment reaches a card when the page it was made on said which
card it was about. The widget embed carries `data-context`, fed by
`SITE_REVIEW_WIDGET_CONTEXT`, and a preview instance sets it to `card:` followed
by the card id. See [Environment variables](../reference/environment.md).

The link is one-way and read-only from the board's side. The card shows the
comment and its status, and the site-review screen still owns that status.
`site_review_mark_comment_addressed` is what marks one done.

Nothing is linked while the board is switched off, and a comment naming a card
of another project is refused. The marker travels through a page, so anyone able
to load it can name any card, and a widget sign-in belongs to one project.

`card_update` reads an omitted field as "leave it alone". `pullRequestUrls` is
the one field where an omitted list and an empty list differ. Omit it and the
links stay. Send `[]` and every link is removed.

### A person deletes a card, an agent does not

The board offers no `card_delete`, and nothing on the MCP surface deletes a
card. An agent finishes a card by moving it to a terminal column, which keeps
the record of the work.

A person deletes a card from the card page. See
[The card page](#the-card-page).

## Turning the board on

`board.enabled` is seeded off, because a board an agent writes to is a second
place work is tracked. The operator opts in.

While the flag is off, the `card_*` tools and `board_columns` are absent from
`tools/list` and from the project's Connect page. An agent never learns of a
tool this instance would refuse. A client that holds an older tool list and
calls one anyway gets a plain refusal rather than a broken call.

Open the flags page at **`/admin/feature-flags`** and switch `board.enabled`
on. The change needs no restart. See [The admin area](admin.md).

Every instance has the row. The install wizard writes it on a fresh install, and
a database migration writes it on an instance that upgrades. Run the migrations
as part of the upgrade, and the flags page lists `board.enabled`, set off.

A missing row reads as off. If the flags page does not list the flag, the
migration has not run. **`/admin/feature-flags/scan`** lists every flag the code
references that the database does not define, and it creates those rows on
request.

## Deleting a project

Deleting a project deletes its board with it, cards, pull request links, card
links and bridge rule reports included.
