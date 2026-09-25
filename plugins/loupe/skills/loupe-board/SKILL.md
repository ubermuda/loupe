---
name: loupe-board
description: "Use when working a project board in the Loupe app through the loupe MCP, calling board_columns, card_create, card_list, card_get, card_update, card_run_open or card_run_close, writing a card, moving a card between columns, linking a document to a card, or linking a pull request to a card."
---

# Working a Loupe board

Each project in Loupe has one board, and each board has its own columns. A card
carries a title, a Markdown body, a type, a status and a reporter.
The status is the slug of the column the card sits in.

The tools act on the project the connection is bound to. An instance can switch the
board off, and every tool then answers "The board is switched off on this
instance."

## Read the columns before you name one

Call `board_columns` before you pass a `status` to any tool. Use the slugs it
returns. `card_list` returns the same list in `columns`, so a session that has
read the board already holds them.

Each column carries these fields:

| Field | Meaning |
|---|---|
| `slug` | The value you pass as `status`. |
| `label` | The name a person sees on the board. |
| `terminal` | `true` for a column that holds finished work. A board has at least one. |
| `default` | `true` for the one column where a card created with no `status` lands. |

A new board starts with `backlog` (default), `next`, `in-progress` and `done`
(terminal). The project owner can add, rename, reorder, flag and delete columns,
so never assume those four. A rename that changes the slug breaks every outside
reference to the old slug. A slug you read in an earlier session can be gone.

An unknown slug is refused, and the error lists the slugs the board has. No tool
writes a column. Ask the owner when the board needs a column it does not have.

This skill names a column by its role. Match each role to a column of the board
by its flags and its label:

- The default column holds work nobody has chosen yet. It is `backlog` on a new
  board.
- The chosen column holds work picked and not started. It is `next` on a new
  board.
- The working column holds work under way. It is `in-progress` on a new board.
- A terminal column holds finished work. It is `done` on a new board.

When no column fits a role, leave the card where it is and tell the owner.

## The tools

| Tool | Use it to |
|---|---|
| `board_columns` | Read the columns of the board, in board order. |
| `card_create` | Put a new card on the board. It lands in the default column unless you pass `status`. |
| `card_list` | Read one page of the board, with its columns. Filter by `status`, `type` or `reporter`. A terminal column reads newest completion first, and every other column reads in rank order. |
| `card_search` | Ask whether a card about something already exists. It reads the title and the body of every card, done ones included. |
| `card_get` | Read one card, with its full Markdown body, its pull request links, its linked documents, its linked cards and the site-review comments pointing at it. |
| `card_update` | Change a card. A field you leave out keeps the value it has. A new status puts the card at the end of the column it arrives in. |
| `card_run_open` | Record an open interactive run on a card when an interactive skill starts work on it. It can move the card in the same step. |
| `card_run_close` | Close the interactive run of your session on a card when the session ends. |

`card_get` and `card_update` take a `cardId`, which you read from `card_list`,
`card_search` or `card_create`. They also take the card `number` in place of the
`cardId`.

An interactive session, such as `/loupe:product-design`, calls `card_run_open`
with `sessionId` set to `$CLAUDE_CODE_SESSION_ID` and `name` set to the skill
name. The run shows on the card and on the worker runs page. While it is open, a
bridge rule with `card: { interactiveRun: false }` skips the card. A move of the
card to another column closes the run, except the move that `card_run_open`
makes with `status`. Call `card_run_close` when the session ends.

## Search before you write a card

Call `card_search` with the words you would use to describe the work, before you
call `card_create`. Reading the whole board to answer the same question costs
far more, and it is the step a session skips.

`query` is required. A blank one is refused rather than read as an empty board.
Matching is by word, not by substring, and words are stemmed, so `paging` finds
`pages`. Quote a phrase to require it, put `-` in front of a word to exclude it,
and write `or` between two words to accept either. A stop word on its own, such
as `the`, matches nothing.

The search covers done cards. "Yes, and it is already done" is a true answer to
"is there a card about this?", and it is the one a list of open cards hides.

It pages the way `card_list` does, with `page`, `perPage`, `total` and
`hasMore`, and `perPage` holds 25 rows by default. A row is the same seven-field
summary, best match first, so call `card_get` for a body.

## `card_list` pages, and its rows are summaries

`card_list` answers one page at a time. `perPage` holds 50 cards by default and
100 at most. `page` counts from 1. Both are clamped into range rather than
refused, so a page past the end reads as an empty list.

The answer carries `page`, `perPage`, `total` and `hasMore`. `total` counts
every card the filters match, not the cards on the page. Keep reading while
`hasMore` is true.

A row carries seven fields: `cardId`, `number`, `title`, `type`, `status`,
`reporter` and `updatedAt`. It carries no body and no links.

Pass `full` to get the whole card on every row, with its Markdown body, its pull
request links, its documents, its linked cards and its site-review comments. A
full page is much larger than a summary page, and a whole board of full cards once overran a
caller's context limit. Read the board as summaries, then call `card_get` for the
one card you want.

`documentIds` links the documents the work is written up in, on `card_create`
and `card_update`. It follows the same omit-versus-empty rule as
`pullRequestUrls`. Unlike a pull request URL, an id naming no document of this
project is **refused** rather than kept: a URL cannot be checked and an id can.
`card_get` returns them as `documents`, each with `documentId`, `title` and
`status`.

`relatedCards` links other cards of this project, on `card_create` and
`card_update`. Each entry takes a `cardId` and a `kind`: `relates-to` (the
default), `blocks` or `blocked-by`. A pair of cards holds one link, and both cards
show it. A `blocks` link from card A reads `blocked-by` from card B. `card_get`
returns `relatedCards` with `cardId`, `number`, `title`, `status` and `kind`, and
you can send that list back unchanged. The omit-versus-empty rule applies. A sent
list replaces every link that touches the card, including links written from the
other card, and nobody tells that card's writer. Read the card before you write.

`siteReviewComments` is read-only, on `card_get` and on `card_list` with `full`.
Each item carries `commentId`, `body`, `url`, `status` and `createdAt`. A comment
reaches a card because the page it was made on named that card, and no board tool
writes that link. Mark one done with `site_review_mark_comment_addressed`, which
takes the same `commentId`, rather than by editing the card.

`card_create` and `card_update` take a `type` of `feature`, `bug`, `security`,
`tooling`, `docs` or `idea`.

- `feature`: a new or extended capability
- `bug`: something behaves incorrectly today, including a latent fault
- `security`: exposure, hardening, or a credential concern
- `tooling`: the development environment, the gates, the scripts, the build
- `docs`: documentation-only work
- `idea`: long-horizon thinking, with no commitment yet

There is no delete tool. You finish a card by moving it to a terminal column,
which stamps its completion time. A move between two terminal columns keeps the
first stamp. A move to a column that is not terminal clears it. Only a person
deletes a card, from the card page.

`card_list` applies no time window to a terminal column, so every finished card
is on the board it pages through, however old it is. The board screen shows the
last 7 days of each terminal column and puts the rest on a history page. A
person therefore sees fewer finished cards than you do.

## A card that opens with `**Parked.**` is paused

The board has no parked state. A card whose body opens with `**Parked.**` waits
for the owner. Leave it in the column it sits in, which is normally the default
column. Never move it to the chosen column or the working column yourself.

Write that line yourself only when the owner parks the work. Board card 'Give
the board a parked state' asks for a real field.

## Reporter says who raised the card

`reporter` records who first raised the card. The tools default it to `agent`.
Pass `human` when you write down something a person decided, rather than
something you found yourself.

Attribute a card to whoever raised it, rather than to whoever typed it. A card
the owner dictated or decided is `human`, even when you write it down. A card
you found on your own is `agent`. An unclear or unattributable card is also
`agent`, because agents absorb the ambiguity, never the person.

`card_create` refuses `reviewer`. The site-review widget writes that value, and
it says the app could not name who raised the card.

The field was called `origin`. `card_create` still accepts that name for one
release, and `reporter` wins when you send both. Write `reporter`.

`reporter` never changes after the card exists. `card_update` has no `reporter`
field, so choose the value when you create the card.

`card_list` takes `reporter` as a filter, and the filter reads all three values.
Pass `reviewer` to read the cards the widget raised.

## A card asks somebody to do something

The board is not a notepad. Every card names work with an addressee, and it ends
when somebody does that work and moves it to a terminal column.

Before you write a card, say in one sentence what somebody must do. A card that
cannot finish that sentence is an observation, and an observation asks nothing of
anyone.

| You have | Where it goes |
|---|---|
| Work somebody must do later | A card |
| A lesson worth applying next time | The skill that covers that area |
| A fact about how the system behaves | `docs/`, or the skill |
| A record of what happened | The commit message or the pull request body |

A lesson is the common mistake, because it feels valuable and it has no owner.
Write it into the skill a future session already reads. A card holding a lesson
sits in the default column forever, because nobody can finish it.

The test survives the rewrite: a card whose body is mostly evidence, with one
line at the end asking for the evidence to be written up, is a card. The write-up
is the work. Put the evidence in the body, so whoever takes it needs no other
source.

## What makes a good card

- Keep one concern per card. Split the card when its body collects a second
  independent piece of work.
- Write a body that makes sense to a session with no context. Say what the work
  is, why it matters, and where the relevant code lives.
- Use absolute dates, such as 2026-09-06. Never write "yesterday" or "last
  week".
- Name the real thing: the class, the route, the file, or the feature. A task
  number, a wave label, a phase name or a spec section is session-ephemeral and
  means nothing later. A pull request number or an issue link is fine, because
  it resolves anywhere.
- The board is as public as the instance that holds it. Write no secrets, no
  customer names, and no complaints about people.

## The card number

Every card has a short number, unique inside its project and counting from 1. A
person says "card 42" and means that number. The tools report the number in
their responses.

The number and the `cardId` are different things. `cardId` is a UUID. When you
hold a number, pass it as `number` to `card_get` or `card_update`. Never send
`number` together with `cardId`, because the tool refuses a call with both. A
number resolves only inside the project your connection is bound to. The other
tools take no number.

## Move the card as the work moves

The card reports where the work stands. A session that reads the board mid-week
sees the truth only when every step updates the card.

| Moment | Do this |
|---|---|
| You start the work | `card_update` with the slug of the working column as `status`, before you write any code. |
| A design document exists | Add its id to `documentIds`, before the code exists. |
| You open the pull request | Add its URL to `pullRequestUrls`. A draft already has a URL. |
| You hand the work over | Put the branch name and the remaining steps in the body. |
| You stop and leave the work | Move the card back to the default column. Say why in the body. |
| The pull request merges | Move the card to a terminal column. |

The chosen column holds work that is chosen and not started. `card_create` takes
`status`, so a card you raise for work you start now goes straight to the
working column.

Attach a link as soon as it exists. A session that reads the card while the
branch runs then finds the URL and the document.

Only the owner parks a card. Read the `**Parked.**` section above before you move
a card out of the default column.

`body` replaces the whole body, in the same way `pullRequestUrls` replaces the
whole set. Read the card with `card_get` first. Add your lines to the Markdown
it returns. Send the whole result. A two-line handover note sent on its own
erases the card.

### A move can start a worker

Every card move writes a `board.card_moved` event. A `loupe bridge` running
against the project starts a worker when a rule in its `rules.yaml` watches the
column the card enters.

- An event that no rule matches starts nothing. No bridge running means no
  worker starts.
- A move that keeps the card in its column, such as a new rank, starts
  nothing.
- A card that `card_create` puts in a column writes no move event, so it starts
  nothing.
- Your own move starts a worker when a rule watches the column you move the
  card to.
- A move that `card_run_open` makes starts nothing under a rule that sets
  `card: { interactiveRun: false }`.

`references/bridge-rules.md` says how a rule matches, caps agent chains and
breaks on a rename.

You cannot read the rule file through the MCP. Ask the owner which columns a
bridge watches before you move a card into a column only to hold it. The example
rule that `cli/README.md` shows, and that the bridge prints when it finds no
rule file, fires on a move to `next`.

## Link a pull request to a card

Three steps carry the link, and you do all three by hand. Nothing automates any
of them.

1. Name the branch after the card number, for example `feat/42-drag-ordering`.
2. Put the card's URL in the pull request body. A card page is at
   `<instance>/projects/<projectId>/board/cards/<cardId>`. The last segment is
   the UUID, never the number.
3. Call `card_update` as soon as you open the pull request, draft included,
   with its URL in `pullRequestUrls`.

No board tool reports the project id, so take it from a project URL you already
hold. Every project URL carries it, including the Connect page at
`/projects/<projectId>/connect`.

`pullRequestUrls` replaces the whole set. Send every URL the card carries. A
call that sends the new URL alone drops the links that were already there. Read
the card with `card_get` first, and send the URLs it already holds with the new
one. Omit the field to keep the current links. Send an empty list to remove them
all.

Step 3 is a convention, and a convention is sometimes forgotten. Steps 1 and 2
exist for that case. A branch named after the card, and a card URL in the pull
request body, let a person recover the association by hand. Nothing reconciles
the three automatically.

## Pull request links accept any forge

A URL from a host the app does not recognise is kept as you sent it. The app
rejects no link, because a self-hosted forge is a legitimate answer.

The app never contacts the forge. A merged pull request does not move its card.
An agent or a person moves the card to a terminal column.

## Common mistakes

| Mistake | Reality |
|---|---|
| Assuming the board has `backlog`, `next`, `in-progress` and `done` | Each board has its own columns. Call `board_columns` and use its slugs. |
| Reusing a slug from an earlier session | A rename can change the slug. Read the columns again. |
| Looking for a tool that adds or renames a column | No tool writes a column. Ask the project owner. |
| Looking for a `card_delete` tool | There is none. Move the card to a terminal column. |
| Carding a lesson so it is not lost | Nobody can finish it. Write it into the skill. |
| Sending only the new URL in `pullRequestUrls` | The field replaces the whole set, so the older links go. |
| Sending an empty `pullRequestUrls` to leave the links alone | An empty list clears them. Omit the field instead. |
| Sending only the new link in `relatedCards` | The field replaces every link of the card, from both ends. Send the whole list from `card_get`. |
| Linking card B back to card A after linking A to B | One link serves both cards, and card B already shows it. Writing it again from B restates it, or changes its kind. |
| Passing a card number as `cardId` | `cardId` is a UUID. Pass the number as `number` instead. |
| Writing a card without checking for one | Call `card_search` first. A duplicate card costs someone a triage pass. |
| Expecting `card_search` to match a substring | It matches whole stemmed words. `pag` finds neither `page` nor `paging`. |
| Reading one `card_list` call as the whole board | It answers one page. Walk the pages while `hasMore` is true. |
| Expecting a body from `card_list` | A row is a summary. Pass `full`, or call `card_get`. |
| Fixing a wrong `reporter` with `card_update` | `reporter` is set once, when the card is created. |
| Passing `origin` to `card_create` | It still works for one release. Write `reporter`. |
| Passing `reporter: reviewer` to `card_create` | Only the site-review widget writes that value. Filtering on it is fine. |
| Expecting a merged pull request to move its card | The app never contacts the forge. Move the card yourself. |
| Linking a pull request only when it is ready for review | A draft has a URL. Link it when you open it. |
| Moving a card into a column only to hold it | A running `loupe bridge` starts a worker when a rule names that column. |
| Leaving the card in the default column while you work on it | The board then shows no work in progress. Move it when you start. |
| Sending a short `body` to add a handover note | `body` replaces the whole body. Read the card with `card_get` first. |
| Writing "Task 3" or "phase 2" in a body | Those names die with the session. Name the class, the route or the file. |
