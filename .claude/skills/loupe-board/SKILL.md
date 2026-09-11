---
name: loupe-board
description: "Use when working a project board in the Loupe app through the loupe MCP, calling card_create, card_list, card_get or card_update, writing a card, or linking a pull request to a card."
---

# Working a Loupe board

Each project in Loupe has one board, and the board has four columns: Backlog,
Next, In progress, and Done. A card carries a title, a Markdown body, a type, a
priority, a status and a reporter. The status is the column the card sits in. The
tools spell the four columns `backlog`, `next`, `in-progress` and `done`.

The tools act on the project your token is bound to. An instance can switch the
board off, and every tool then answers "The board is switched off on this
instance."

## The four tools

| Tool | Use it to |
|---|---|
| `card_create` | Put a new card on the board. It lands in `backlog` unless you pass `status`. |
| `card_list` | Read one page of the board. Filter by `status`, `type` or `priority`. Done reads newest completion first, and every other column reads highest priority first. |
| `card_get` | Read one card, with its full Markdown body, its pull request links, its linked documents and the site-review comments pointing at it. |
| `card_update` | Change a card. A field you leave out keeps the value it has. A new status or priority puts the card at the end of the column it arrives in. |

`card_get` and `card_update` take a `cardId`, which you read from `card_list` or
`card_create`.

## `card_list` pages, and its rows are summaries

`card_list` answers one page at a time. `perPage` holds 50 cards by default and
100 at most. `page` counts from 1. Both are clamped into range rather than
refused, so a page past the end reads as an empty list.

The answer carries `page`, `perPage`, `total` and `hasMore`. `total` counts
every card the filters match, not the cards on the page. Keep reading while
`hasMore` is true.

A row carries eight fields: `cardId`, `number`, `title`, `type`, `priority`,
`status`, `reporter` and `updatedAt`. It carries no body and no links.

Pass `full` to get the whole card on every row, with its Markdown body, its pull
request links, its documents and its site-review comments. A full page is much
larger than a summary page, and a whole board of full cards once overran a
caller's token limit. Read the board as summaries, then call `card_get` for the
one card you want.

`documentIds` links the documents the work is written up in, on `card_create`
and `card_update`. It follows the same omit-versus-empty rule as
`pullRequestUrls`. Unlike a pull request URL, an id naming no document of this
project is **refused** rather than kept: a URL cannot be checked and an id can.
`card_get` returns them as `documents`, each with `documentId`, `title` and
`status`.

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

They also take a `priority` of `high`, `medium` or `low`. Write the name, never
a number.

There is no delete tool. You finish a card by moving it to `done`, which stamps
its completion time. Moving it out of `done` clears that stamp. Only a person
deletes a card, from the card page.

`card_list` applies no time window to Done, so every done card is on the board it
pages through, however old it is. The board screen shows the last 7 days of Done
and puts the rest on a history page, so a person sees less of Done than you do.

## A card that opens with `**Parked.**` is paused

The board has no parked state. A card whose body opens with `**Parked.**` waits
for the owner. Leave it in `backlog`, and never move it to `next` or
`in-progress` yourself.

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

The number and the `cardId` are different things. `cardId` is a UUID, and it is
what `card_get` and `card_update` take. No tool looks a card up by its number,
so read the board with `card_list` when you hold a number and need the id.

## Link a pull request to a card

Three steps carry the link, and you do all three by hand. Nothing automates any
of them.

1. Name the branch after the card number, for example `feat/42-drag-ordering`.
2. Put the card's URL in the pull request body. A card page is at
   `<instance>/projects/<projectId>/board/cards/<cardId>`. The last segment is
   the UUID, never the number.
3. Call `card_update` after you open the pull request, with its URL in
   `pullRequestUrls`.

No board tool reports the project id, so take it from a project URL you already
hold. Every project URL carries it, including the Connect page at
`/projects/<projectId>/connect`.

`pullRequestUrls` replaces the whole set. Send every URL the card carries. A
call that sends the new URL alone drops the links that were already there. Omit
the field to keep the current links. Send an empty list to remove them all.

Step 3 is a convention, and a convention is sometimes forgotten. Steps 1 and 2
exist for that case. A branch named after the card, and a card URL in the pull
request body, let a person recover the association by hand. Nothing reconciles
the three automatically.

## Pull request links accept any forge

A URL from a host the app does not recognise is kept as you sent it. The app
rejects no link, because a self-hosted forge is a legitimate answer.

The app never contacts the forge. A merged pull request does not move its card.
An agent or a person moves the card to `done`.

## Common mistakes

| Mistake | Reality |
|---|---|
| Looking for a `card_delete` tool | There is none. Move the card to `done`. |
| Sending only the new URL in `pullRequestUrls` | The field replaces the whole set, so the older links go. |
| Sending an empty `pullRequestUrls` to leave the links alone | An empty list clears them. Omit the field instead. |
| Passing a card number as `cardId` | `cardId` is a UUID. Find it with `card_list`. |
| Reading one `card_list` call as the whole board | It answers one page. Walk the pages while `hasMore` is true. |
| Expecting a body from `card_list` | A row is a summary. Pass `full`, or call `card_get`. |
| Fixing a wrong `reporter` with `card_update` | `reporter` is set once, when the card is created. |
| Passing `origin` to `card_create` | It still works for one release. Write `reporter`. |
| Passing `reporter: reviewer` to `card_create` | Only the site-review widget writes that value. Filtering on it is fine. |
| Expecting a merged pull request to move its card | The app never contacts the forge. Move the card yourself. |
| Writing "Task 3" or "phase 2" in a body | Those names die with the session. Name the class, the route or the file. |
