---
title: "Board"
description: "A four-column work board per project, with drag to rank and re-grade. Off by default."
---

The board is one page per project at `/projects/{id}/board`. It holds cards,
which are pieces of work an agent or a person raised. An agent writes to it
through the `card_create`, `card_update`, `card_list` and `card_get` MCP tools;
a person reads and rearranges it in the browser.

**The board ships switched off.** An operator turns on the `board.enabled`
feature flag in the admin area before the page and the MCP tools appear. Until
then the sidebar shows no Board link, and every board route answers 404.

## The four columns

Left to right: **Backlog**, **Next**, **In progress** and **Done**.

Inside the first three, cards group under a priority heading: High, then Medium,
then Low. A person ranks the cards by hand inside each group, and the board
keeps that rank.

**Done is different.** It sorts by the moment a card was finished, newest first,
and keeps no hand-made rank. The column shows the cards finished in the last
seven days, with a link to the full history at
`/projects/{id}/board/done`.

## Moving a card

Drag a card by the grip in its top right corner. Three things can happen:

- Drop it elsewhere in the same priority group, and the group is renumbered.
- Drop it under another priority heading in the same column, and the card takes
  that grade. It lands at the end of the group.
- Drop it in another column, and the card takes that status. It lands at the end
  of the group there.

The server decides the result. A drop posts the move, and the answer redraws the
whole board, so a drag that is interrupted or refused leaves the order the
database holds rather than one the page invented.

Every card also carries a **Move** disclosure with a column select and a
priority select. That is the same endpoint the drop uses, and it works with a
keyboard alone and with JavaScript switched off.

## A card

A card has a title, a Markdown description, a type (feature, bug, security,
tooling, docs or idea), a priority and a column. It also records whether an
agent or a person first raised it.

The card face shows the title, the type and a count when pull requests are
linked to it. The description is rendered on the card's own page, which is where
the Edit and Delete controls live too. Deleting a card is permanent and asks for
confirmation.

## Pull request links

A card can carry several pull request URLs. Loupe reads the forge, the
`owner/repo` pair and the number from a URL whose shape it recognises, and shows
them as a link. A URL from a forge it does not recognise is kept exactly as
given, rather than refused.

Links are edited on the card's edit form, one URL per line. **Saving replaces
the whole list**, so an empty box removes every link.
