---
name: loupe-board
description: "Use when working a project board in the Loupe app through the loupe MCP, calling card_create, card_list, card_get or card_update, writing a card, or linking a pull request to a card."
---

# Working a Loupe board

Each project in Loupe has one board, and the board has four columns: Backlog,
Next, In progress, and Done. A card carries a title, a Markdown body, a type, a
priority, a status and an origin. The status is the column the card sits in. The
tools spell the four columns `backlog`, `next`, `in-progress` and `done`.

The tools act on the project your token is bound to. An instance can switch the
board off, and every tool then answers "The board is switched off on this
instance."

## The four tools

| Tool | Use it to |
|---|---|
| `card_create` | Put a new card on the board. It lands in `backlog` unless you pass `status`. |
| `card_list` | Read the board. Filter by `status`, `type` or `priority`. Done reads newest completion first, and every other column reads highest priority first. |
| `card_get` | Read one card, with its full Markdown body and its pull request links. |
| `card_update` | Change a card. A field you leave out keeps the value it has. A new status or priority puts the card at the end of the column it arrives in. |

`card_get` and `card_update` take a `cardId`, which you read from `card_list` or
`card_create`.

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
deletes a card.

## Origin says who raised the card

`origin` records who first raised the card. The tools default it to `agent`.
Pass `human` when you write down something a person decided, rather than
something you found yourself.

Attribute a card to whoever originated it, not to whoever typed it. A card the
owner dictated or decided is `human`, even when you write it down. A card you
found on your own is `agent`. An unclear or unattributable card is also `agent`,
because agents absorb the ambiguity, never the person.

`origin` never changes after the card exists. `card_update` has no `origin`
field, so choose the value when you create the card.

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
2. Put the card's URL in the pull request body.
3. Call `card_update` after you open the pull request, with its URL in
   `pullRequestUrls`.

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
| Fixing a wrong `origin` with `card_update` | `origin` is set once, when the card is created. |
| Expecting a merged pull request to move its card | The app never contacts the forge. Move the card yourself. |
| Writing "Task 3" or "phase 2" in a body | Those names die with the session. Name the class, the route or the file. |
