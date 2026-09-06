---
title: "The project board"
description: "The four columns, how cards are ordered, and the four MCP tools an agent drives them with."
---

Every project has one board, and the board holds cards. A card describes one
piece of work: what it asks for, how urgent it is, and which column it sits in.
An agent reads and writes the board through the MCP endpoint.

The board is behind the `board.enabled` feature flag, and the flag ships off. A
fresh install has no board until an operator switches it on. See
[Turning the board on](#turning-the-board-on).

This version has no board screen. The four MCP tools are the only way to read
the board or write to it.

## The four columns

A card sits in exactly one column, and the tools name it by the value beside it.

| Column | Value |
|---|---|
| Backlog | `backlog` |
| Next | `next` |
| In progress | `in-progress` |
| Done | `done` |

A new card lands in Backlog unless it names another column.

## How cards are ordered

Cards group by priority inside a column. High reads first, then medium, then
low. Inside one priority group each card holds a rank, counting from 0. A column
therefore reads as three ranked lists, one for each priority.

The rank is a plain integer, and Loupe renumbers a group from 0 after every
change. A group carries no gaps, so the rank a tool reports is the card's real
place in its list.

A move inside one group takes a target rank and puts the card there. A move that
changes the column or the priority behaves differently. It appends the card to
the end of the group it arrives in, and it renumbers the group the card left so
no gap remains.

The MCP tools carry no rank argument. `card_create` appends a new card to its
group, and `card_update` appends a card whose column or priority changed. An
agent cannot re-rank a group.

Two cards that tie on priority and rank read oldest first, and then by id, so a
column comes back in the same order on every read.

## Done behaves differently

Done sorts by completion time, newest first. It keeps no rank, and every card in
Done reports the rank 0.

A card that enters Done is stamped with the moment it arrived. A card that moves
inside Done keeps that first stamp. A card that leaves Done loses the stamp, and
takes a new one if it comes back.

This version shows the whole of Done. `card_list` returns every done card,
however old it is, and applies no time window to the column.

## What a card holds

| Field | What it is |
|---|---|
| Title | Plain text, up to 255 characters. Loupe trims it and refuses a blank one. |
| Body | Markdown. It says what the card asks for. |
| Type | One of `feature`, `bug`, `security`, `tooling`, `docs`, `idea`. |
| Priority | One of `high`, `medium`, `low`. |
| Status | The column the card sits in. |
| Origin | `human` or `agent`. It records who raised the card. |
| Pull requests | Any number of links. See below. |

A card also carries the moment it was created and the moment it last changed. A
card in Done carries its completion time as well.

Origin never changes. `card_update` refuses that field, because it answers who
first raised the card rather than who touched it last. An MCP request
authenticates as the project owner, so the tools cannot tell an agent's own card
from one a person dictated. An agent writing down what a person asked for passes
`human` at creation.

## Pull request links

A card links to any number of pull requests. Nothing about a link is
GitHub-specific. Loupe stores the URL as you give it, up to 512 characters, and
puts whatever a parser could read beside it.

Loupe ships one parser, for `https://github.com/<owner>/<repo>/pull/<number>`.
Such a URL is stored with the forge `github`, the `owner/repo` pair and the
number. Every other URL is stored with the forge `other`, and with no repository
and no number. That covers a URL from another forge, a self-hosted one, and one
that matches no shape Loupe knows.

Loupe refuses no URL. A link it cannot read is still the link a reviewer wants
on the card. Two identical URLs in one call are stored once, a blank entry is
dropped, and the links read back in the order they were added.

### Loupe reads no forge

This version makes no outbound call to GitHub or to any other host. Loupe never
asks whether a linked pull request is open, merged or closed.

A merged pull request therefore does not move its card. Nothing watches the
forge, and there is no webhook to point at Loupe. Move the card to Done
yourself, or have your agent move it with `card_update`.

## The four MCP tools

An agent drives the board through the MCP endpoint. See
[The MCP endpoint](mcp.md) for the token and the client setup.

| Tool | Arguments |
|---|---|
| `card_create` | `title`, `body`, `type` and `priority` are required. `status`, `origin` and `pullRequestUrls` are optional. |
| `card_list` | `status`, `type` and `priority`, each optional, each a filter. |
| `card_get` | `cardId`. |
| `card_update` | `cardId` is required. `title`, `body`, `type`, `priority`, `status` and `pullRequestUrls` are optional. |

`card_list` returns the whole board when you give it no filter. It reports the
cards and their total. Every column except Done reads in board order, highest
priority first and then by rank. Done reads newest completion first.

`card_get` returns one card with its full Markdown body and every pull request
linked to it. Use a card id that `card_list` or `card_create` gave you.

`card_update` reads an omitted field as "leave it alone". `pullRequestUrls` is
the one field where an omitted list and an empty list differ. Omit it and the
links stay. Send `[]` and every link is removed.

### There is no delete tool

The board offers no `card_delete`, and nothing on the MCP surface deletes a
card. An agent finishes a card by moving it to `done`, which keeps the record of
the work.

## Turning the board on

`board.enabled` is seeded off, because a board an agent writes to is a second
place work is tracked. The operator opts in.

While the flag is off, the four `card_*` tools are absent from `tools/list` and
from the project's Connect page. An agent never learns of a tool this instance
would refuse. A client that holds an older tool list and calls one anyway gets a
plain refusal rather than a broken call.

### On a fresh install

The install wizard writes a `board.enabled` row and sets it off. Open the flags
page at **`/admin/feature-flags`** and switch it on. The change needs no restart.

### On an instance installed before the board existed

Such an instance has no `board.enabled` row at all. A missing row reads as off,
so the board stays invisible and the flags page offers no switch for it.
Upgrading does not create the row either, because a deploy runs the database
migrations and nothing else.

Create the row first, in one of two ways:

- Open **`/admin/feature-flags/scan`**. It lists every flag the code references
  that the database does not define, and it creates those rows on request.
- Open **`/admin/feature-flags/new`**. Set **Name** to `board.enabled`, set
  **Type** to `Bool`, then set the value.

Switch the flag on at **`/admin/feature-flags`** afterwards. See
[The admin area](admin.md).

## Deleting a project

Deleting a project deletes its board with it, cards and pull request links
included.
