---
title: "Workflows"
description: "How a workflow template moves the cards of a board, asks a bridge for work, and writes to GitHub."
---

Each board follows a workflow template. The template names the places of the
work, called slots, and the rules that act on a card. The workflow engine of
Loupe checks the rules of a card on each change and on a timer. A rule fires
when its condition turns true. It then moves the card, asks a bridge for work,
writes to GitHub, or pauses the card.

A bridge does the work that the workflow asks for. It does not decide when work
runs. See [Command-line bridge](../extending/cli-bridge.md) for how a bridge
maps each kind of work to an agent.

## Templates

Loupe ships two templates.

| Template | For |
|---|---|
| **Lifecycle** | The process of this repository: product design, tech design, implementation, review and merge, with epics and blockers |
| **Simple** | Any board. A merge moves the card to the terminal column, and an abandoned pull request returns the card to the Backlog |

A new project picks its template during setup. The template creates its columns
with its slots linked. A project that existed before the workflow engine got a
template when the engine was switched on. A board that had a column for each
Lifecycle slot got Lifecycle, and every other board got Simple. The engine
treated every condition that was true at that time as handled, so nothing moved
at once.

The **Workflow** page of the project shows the template of the board, its slots
and the column each slot links to, and its rules. The page is read-only. A later
release adds a way to change the template.

## Slots and columns

A slot links to a column by the column's id. A rename of the column keeps the
link, so the rules keep working. A deleted column leaves its slot empty, and a
card that needs that slot pauses with "workflow slot missing".

The Backlog and the terminal columns are not slots. A rule names them as
`@backlog` and `@terminal`.

## Managed cards

A card is managed by default. A managed card follows the template, and a person
may make only the moves that the template lists. Any other move offers to make
the card unmanaged first. An unmanaged card is outside the workflow: the
workflow makes no move and asks for no work on it.
[The board](board.md#managed-and-unmanaged-cards) describes both states, and
the Workflow panel of the card page.

## Pauses and retries

The workflow pauses a card when a rule asks for it, and when work keeps failing.
A refused action fires again as soon as a fact that its rule reads changes, such
as a new head commit. Otherwise it retries after 10 minutes, after 1 hour and
after 6 hours. After the last retry the card pauses, and the owner's inbox gets
an item that names the reason.

A work request that no bridge takes within 2 hours pauses the card with "no
bridge took the work". A teardown request is the exception: it expires with no
pause.

## Writes to GitHub

A rule can write to a pull request: merge it, update its branch, change its
base, switch an epic pull request between draft and ready, or close it. Each
write is off until the owner turns it on, on the **Automation** tab. With a
write off, the rule asks a bridge for the same work instead. See
[Automation](board.md#automation).

## The Lifecycle template

Lifecycle has the slots Next, Product design, Tech design, Implementation and In
review. A person can move a card from the Backlog to Next, Product design or
Tech design, and from Next back to the Backlog or on to a design slot.

| Slot | The workflow |
|---|---|
| Product design | Asks for an interactive product design session, and asks for a revision when the product document gets changes requested. An approved product document, with the tag `product`, moves the card to Tech design |
| Tech design | Asks for the tech design, and for a revision when it gets changes requested. An approved tech design, with the tag `design`, moves the card to Implementation once the card has no open blocker |
| Implementation | Asks for the implementation, or for a breakdown of an epic into children. A pull request that is open, not a draft and whose required checks passed moves the card to In review. Failed checks, a conflict or a request for changes ask for a fix, 3 rounds at most |
| In review | Asks for a fix as in Implementation. A pull request that turns back into a draft moves the card to Implementation. A stacked pull request whose parent merged gets a new base. An approved pull request that is behind gets its branch updated. A ready pull request merges |

A pull request is ready to merge when it is reviewable, an approval covers its
head, its base is the default branch or the card's epic branch, and it has no
conflict. A merge from the base after the approval keeps the approval. New
commits need a new approval.

Some rules act from any slot:

1. A card whose pull requests all finished, with one merged, moves to the
   terminal column once no child is open.
2. A card whose pull requests all closed with none merged returns to the Backlog
   after 10 minutes. A reopen in that time keeps the card.
3. A card in the Backlog whose pull request reopens moves to Implementation.
4. A child in the Backlog whose last blocker finished moves to Implementation.
5. A card that reaches a terminal column asks for a teardown, which removes its
   worktree on the bridge.

An epic follows its children. An epic whose children all finished moves to In
review when it has a pull request, and to the terminal column when it has none.
A new open child moves it back to Implementation. With the epic writes on, the
pull request of an epic is a draft in Implementation, turns ready in In review,
and closes when the epic returns to the Backlog.

## The Simple template

Simple has no slots, and a person can make any move. It has three rules: a card
whose pull requests all finished with one merged moves to the terminal column, a
card whose pull requests all closed with none merged returns to the Backlog after
10 minutes, and a card that reaches a terminal column asks for a teardown.

## Kinds of work

A rule that asks for work names its kind. A bridge runs a kind only when its
`work:` map has an entry for it. Lifecycle asks for these kinds:

| Kind | Asked for |
|---|---|
| `product-design` | A product design session. It needs a bridge that can open an interactive session |
| `product-design-revise` | A revision of the product document |
| `tech-design` | The tech design |
| `tech-design-revise` | A revision of the tech design |
| `implement` | The implementation and its pull request |
| `breakdown` | The child cards of an epic |
| `fix` | A fix of a failed check, a conflict or a request for changes |
| `rebase-stacked` | A new base for a stacked pull request, with the change base write off |
| `sync` | An update of a branch that is behind, with the sync write off |
| `merge` | A merge, with the merge write off |
| `teardown` | The removal of the card's worktree |

Simple asks for `teardown` alone.
