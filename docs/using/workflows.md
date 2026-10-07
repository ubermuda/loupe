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
| **Simple** | Any board. A merge moves the card to the terminal column |

A new project picks its template during setup. The template creates its columns
with its slots linked. A project that existed before the workflow engine got a
template when the engine was switched on. A board that had a column for each
Lifecycle slot got Lifecycle, and every other board got Simple. A project that already had a
template got the current copy of it. The engine
treated every condition that was true at that time as handled, so nothing moved
at once.

The **Workflow** page of the project shows the template of the board, its slots
and the column each slot links to, and its rules. The page is read-only. A later
release adds a way to change the template.

The page lists the conditions of each rule. It groups them by the module whose
data they read: Board, Forge or Bridge. A rule whose condition no longer exists
on this instance gets an amber tag that names the condition.

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

A bridge can settle a work request as refused, for example when its worker run
fails. In Lifecycle, the workflow then retries the work up to three times,
after 2, 3 and 5 minutes, when the refusal code is `failed` or `timeout`. A
retry never counts toward the work limit of a fix rule. When the third retry
also fails, the card pauses with "too many attempts were refused". Any other
refusal code, such as `unfinished` or `work-remains`, pauses the card at once
with "the worker stopped and needs a person". The board marks a card whose latest worker run failed or ended with no
result, until a later run ends in another state.

A work request that no bridge takes within 2 hours pauses the card with "no
bridge took the work". A teardown request is the exception: it expires with no
pause.

A person ends a pause with **Retry now** in the Workflow panel of the card. The
button shows for a pause after too many retries, after the work limit, after
no bridge took the work, or after the worker stopped. It needs the permission to manage the project. A
release is refused on an unmanaged card. It is also refused on a pause that a
rule made, because that pause ends only on its own release condition. After a
release the paused rule runs again with a fresh budget of retries and work. The
card history records the pause and the release, with the person who released
it. An agent ends a pause with the `card_pause_release` MCP tool.

## Writes to GitHub

A rule can write to a pull request: merge it, update its branch, change its
base, open an epic pull request, switch it between draft and ready, or close
it. Each
write is off until the owner turns it on, on the **Automation** tab. With a
write off, the rule asks a bridge for the same work instead. See
[Automation](board.md#automation).

## The Lifecycle template

Lifecycle has the slots Next, Product design, Tech design, Implementation and In
review. A person can move a card from the Backlog to Next, Product design or
Tech design, and from Next back to the Backlog or on to a design slot. A person
can also move a card from Implementation back to Tech design, for example when
the card has no tech design yet. A card whose tech design is approved goes
back to Implementation at once when it has no open blocker.

| Slot | The workflow |
|---|---|
| Product design | Asks for an interactive product design session when the card has no product document, and asks for a revision when the product document gets changes requested. An approved product document, with the tag `product-design`, moves the card to Tech design |
| Tech design | Asks for the tech design when the card has no design document, and for a revision when it gets changes requested. An approved tech design, with the tag `tech-design`, moves the card to Implementation once the card has no open blocker |
| Implementation | Asks for the implementation, or for a breakdown of an epic into children. An epic gets its breakdown when it enters, with or without children. A pull request that is open, not a draft and whose required checks passed moves the card to In review. Failed checks, a conflict or a request for changes ask for a fix, 3 rounds at most |
| In review | Asks for a fix as in Implementation. A pull request that turns back into a draft moves the card to Implementation. A stacked pull request whose parent merged gets a new base. An approved pull request that is behind gets its branch updated. A ready pull request merges |

A design document in review, a draft and a revision keep the slot from asking
for a second design. Archive the document to ask for a new one.

An upgrade retags the existing documents. The tag `product` becomes
`product-design`, and the tag `design` becomes `tech-design`.

A pull request is ready to merge when it is reviewable, an approval covers its
head, its base is the default branch or the card's epic branch, and it has no
conflict. A merge from the base after the approval keeps the approval. New
commits need a new approval.

Some rules act from any slot:

1. A card whose pull requests all finished, with one merged, moves to the
   terminal column once no child is open.
2. A card in the Backlog whose pull request reopens moves to Implementation.
3. A child in the Backlog whose last blocker finished moves to Implementation.
   It waits while a breakdown of its epic runs. When that breakdown ends, the
   epic evaluates its children again. The wait reads the open worker runs of
   the epic. An older bridge that sends no run key reports a run only after it
   ends, so its breakdown does not hold the children.
4. A card that reaches a terminal column asks for a teardown, which removes its
   worktree on the bridge.
5. A child that reaches a terminal column with a pull request merged into its
   epic branch asks for an epic preview refresh.

An epic follows its children. An epic whose children all finished moves to In
review when it has a pull request, and to the terminal column when it has none.
It moves on only after its breakdown request ends.
When a child merges into the epic branch and the epic has no open pull request, the
workflow opens the epic pull request. Such an epic never moves straight to the
terminal column. It goes through In review with its epic pull request. With
the open write off, the rule refuses and tries again later. The epic waits in
Implementation until a person turns the write on or links a pull request.
Turning the write on opens the pull request of each waiting epic, and ends the
pause of an epic that ran out of retries. A save that also turns the automation
on does neither, so turn the write on in a separate save.
A new open child moves it back to Implementation. With the epic writes on, the
pull request of an epic is a draft in Implementation, turns ready in In review,
and closes when the epic returns to the Backlog.

## The Simple template

Simple has no slots, and a person can make any move. It has two rules: a card
whose pull requests all finished with one merged moves to the terminal column,
and a card that reaches a terminal column asks for a teardown.

In both templates, a card whose pull requests all closed with none merged stays
in its column. A person or an agent moves it.

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
| `epic-preview` | A refresh of the epic preview after a child merges into the epic branch |

Simple asks for `teardown` alone.

## What a request carries

A request for work carries the context of its card, as the card was when the
request opened. The bridge can fill a prompt or a command with each value.

A rule that reads a pull request tries the open pull requests of the card from
the bottom of a stack first, then the oldest opened. It acts on the first one
that makes its condition true. The fix limit counts per pull request: when the
rule moves to another pull request, its count starts again.

| Value | What it holds |
|---|---|
| Pull request number | the number of the pull request that the rule acts on |
| Pull request link | the link to that pull request, as the card holds it |
| Head commit | the head commit of that pull request. A later push leaves it behind |
| Reason | `conflict`, `checks-failed` or `changes-requested`, from the state of that pull request |
| Document | the id of the document that a revision works on |

A request rule names its document with the optional `document` parameter. It is
a map with the key `tag`, and an optional `status`: `in-review`, `approved`,
`changes-requested` or `draft`.

```yaml
- id: tech-design-revise
  slot: tech-design
  when: { card.document_changes_requested: { tag: tech-design } }
  then:
      request:
          kind: tech-design-revise
          document: { tag: tech-design, status: changes-requested }
```

The request then names the one unarchived document of the card that carries the
tag, and that has the status when the map names one. No such document refuses
the action with `document-not-found`. Two or more refuse it with
`document-ambiguous`. A refused action retries and pauses as
[Pauses and retries](#pauses-and-retries) says. In Lifecycle, the product design
revision names the document with the tag `product-design` and changes requested.
The tech design revision names the one with the tag `tech-design` and changes
requested.
An approved document with the same tag does not count. A request rule with no `document`
parameter names no document.
