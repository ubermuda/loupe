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

The template also declares the card types of the board. Each type has a key, a
label and a colour. A type can have children, and it can get a lane on the
board. Both shipped templates declare the same seven types, and only Epic has
children and a lane. Feature is the default type. The **Workflow** page lists
the types.

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

A move in the template can name who may make it. A move with
`by: parent-run` is open to an open worker run of the parent epic, and not
to a person. The run may be of any work kind, such as a breakdown or a fix.
An interactive run does not count. Lifecycle names no such move. A move with
no `by` is open to anyone. The **Who** column of the manual moves on the Workflow settings page shows who may
make each move.

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
also fails, the workflow starts a repair, as the next paragraphs say. Any other
refusal code, such as `unfinished` or `work-remains`, pauses the card at once
with "the worker stopped and needs a person". The board marks a card whose
latest worker run failed or ended with no result, until a later run ends in
another state.

When the retries run out, the workflow opens one `repair` work request for the
card. The request names the failed rule in `{ruleId}`, and the last refusal
code in `{reason}`. A repair worker reads the failed run, fixes the cause, and
reports. When the repair ends done, the failed rule gets one last try. When the
repair fails, or the last try fails too, the card pauses with "too many
attempts were refused". A failed repair records the code `repair-failed`.

Each escalation starts one repair at most. A repair request is never retried
and never repaired. A release of the pause starts a new escalation, so the
next failure can start one more repair. A bridge with no `repair` entry lets
the request expire after the work timeout, and the card then pauses with "no
bridge took the work". [The development
lifecycle](../contributing/lifecycle.md) shows a `repair` entry.

A resumed run ends the pause of its card. When a person resumes a run, or a
closed [inbox](inbox.md) ask resumes it, the workflow releases the pause as soon
as the resumed run reports to Loupe. This holds for every pause a person can
release, and not for a pause that a rule sets with its own release condition.
The card then asks for no second worker. A resumed run that fails earns the
retries of a fresh budget, and a resumed run that ends as blocked pauses the
card again at once.

The template sets this behaviour in its `onWorkFailed` block:

```yaml
onWorkFailed:
    retryOn: [failed, timeout]
    retries: 3
    backoffMinutes: [2, 3, 5]
    repair: { kind: repair }
```

`retryOn` lists the refusal codes that retry, and `retries` counts the retries
before the repair. `backoffMinutes` gives the wait before each retry. `repair`
names the kind of the repair request. A template with no `repair` key pauses
the card when the retries run out. A template with no `onWorkFailed` block
neither retries nor pauses after a refused request.

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

Two more writes come from the [rules the app adds](#rules-the-app-adds). They
act on a site-review verdict, and they never ask a bridge for work:

| Write | Does |
|---|---|
| `post-review` | Posts each unsent verdict as a review on the open GitHub pull requests that the reviewer picked. The review goes out under the reviewer's own GitHub account |
| `site-review-check` | Keeps a check named "Loupe site review" on each open GitHub pull request of the card. The check goes out as the GitHub App |

A review that the reviewer sends on their own pull request becomes a comment,
because GitHub refuses a review from the author of a pull request.

With its opt-in off, a write still settles its rows. A verdict is then stored,
marked as not sent, and a later verdict starts a new write. The review write
marks the delivery as skipped. The check write records what it would have
posted. Turning an opt-in on later posts nothing for a verdict that was settled
before.

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
conflict. A child pull request into its epic branch also waits while any
worker run of the epic is open. A merge from the base after the approval keeps
the approval. New commits need a new approval.

Some rules act from any slot:

1. A card whose pull requests all finished, with one merged, moves to the
   terminal column once no child is open. A merged epic also waits while any
   worker run of it is open.
2. A card in the Backlog whose pull request reopens moves to Implementation.
3. A child of an epic starts only when it links an approved tech design. A
   child in the Backlog moves to Implementation when it has a document with the
   tag `tech-design` and the status approved, it has no open blocker, and it has
   no pull request. It waits while the epic has work in progress. That work is
   any open worker run of the epic, and any work that the workflow requested for
   the epic and no bridge finished. When the last such work ends, the epic
   evaluates its children again, in any column. A change to the work requests
   of the epic, or to the documents of the child, also evaluates the child. An
   older bridge that sends no run key reports a run only after it ends, so its
   run does not hold the children. In a workflow file, the condition
   `parent.document_approved` with a `tag` is true when a document of the parent
   card has that tag and is approved.
4. A child in the Backlog that links no approved tech design asks the owner
   what to do, once the epic has an approved tech design and no work in
   progress. The rule is `unplanned-child`. See
   [Asking about an unplanned child](#asking-about-an-unplanned-child).
5. A card that reaches a terminal column asks for a teardown, which removes its
   worktree on the bridge.
6. A child that reaches a terminal column with a pull request merged into its
   epic branch asks for an epic preview refresh.

An epic follows its children. An epic whose children all finished moves to In
review when it has a pull request, and to the terminal column when it has none.
It moves on only after every work request of the epic ends.
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

### Asking about an unplanned child

A child that joins an epic after the epic design was approved may link no tech
design. The `unplanned-child` rule puts a question in the
[inbox](inbox.md#workflow-questions) of the project. The question names the
child and the epic, and it has three options:

1. **Link the tech design of the epic.** The child links the tech design of the
   epic, whatever its status. When that design is approved, the child starts
   at once if it has no open blocker. If the design is back in review, the
   child waits until the epic design is approved again. When the epic has no
   tech design, nothing is linked and the card history records the refusal.
   The implementation worker then builds the card body against the epic
   design. The plan covers only the work that the card body describes.
2. **Move the card to Tech design.** The child gets a tech design of its own.
3. **Detach the card.** The card stops being a child of the epic.

The answer is final. Loupe runs the option a short time after you answer,
because a queue carries the answer to the workflow. The question closes by
itself, as withdrawn, when the rule stops holding: for example when the child
links a design, when the epic starts new work, or when the card moves away from
the Backlog. Loupe also withdraws it when the card is held or deleted. A
question that you answered stays answered.

When the inbox is off, the rule cannot ask. The child then pauses with the
reason `inbox-off`. Turn the inbox on, then release the pause, and the
question opens.

In a workflow file, the action `ask` takes a `question` key and a list of
`options`. Each option has a `label` and a `then` list of actions. The action
`link-document` links a document of the parent card to the card. It takes
`from: parent` and a `tag`. The action `detach` removes the parent of the
card. The `question` and each `label` are translation keys. They can use the
parameters `%child%` and `%epic%`, which hold the card numbers.

## The Simple template

Simple has no slots, and a person can make any move. It has two rules: a card
whose pull requests all finished with one merged moves to the terminal column,
and a card that reaches a terminal column asks for a teardown.

In both templates, a card whose pull requests all closed with none merged stays
in its column. A person or an agent moves it.

## Rules the app adds

Loupe adds its own rules to the rules of every template. The **Workflow** page
lists them in their own group, **Rules the app adds**, below the rules of the
template. The group shows only when the app adds at least one rule. An app rule
watches the Backlog, a terminal column, or every column, because each template
names its own slots. No template rule can take the id of an app rule.

A request of an app rule can carry a prompt that ships with Loupe. A bridge that
sets `appPrompts: true` runs it for a kind that its `work:` map does not hold.
See [Command-line bridge](../extending/cli-bridge.md#work-requests).

The app adds three rules.

The rule `discovery` watches the Backlog. It asks for work of
kind `discovery` when the card carries a requested
[discovery run](workshop.md#run-discovery), and it sends the discovery prompt
with the request. A request that no bridge takes expires, and the run fails with
the reason that no bridge took the work.

The rules `post-widget-review` and `sync-site-review-check` watch every column.
They act on the verdict that a reviewer sends from the
[site-review widget](site-review.md). Each reads one condition of the Board
group, and each fires one write:

| Rule | Condition | Write |
|---|---|---|
| `post-widget-review` | `site_review.verdict_unsent`: a verdict of the card has a pull request that no review settled yet | `post-review` |
| `sync-site-review-check` | `site_review.check_stale`: an open GitHub pull request of the card has no check for its head commit, a check with another result than the one the card now wants, or a check that lists other notes than the card now carries | `site-review-check` |

The review is posted under the reviewer's own account. The reviewer connects
that account from the widget. A review that the connection cannot send, because
it is missing or expired, stays refused with the reason `connection-expired`.
The next connection of the same reviewer sends it again.

The check counts the pending notes that a verdict carried. A note that no
verdict carries yet does not count. The check is green when no such note is
pending. It fails when one or more are pending, and its summary lists them. A
push to the pull request, and a note that turns addressed or resolved, post the
check again.

Both rules need the engine to read the facts of the Board module. When it
cannot read them, only these two rules wait. Every other rule of the card runs.
Neither rule can be removed from a project, but each write stays off until its
opt-in is on.

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
| `repair` | A repair of the cause after the work of a rule failed and its retries ran out |

Simple asks for `teardown` alone.

Each request of a shipped template carries a `checks` list. The list names what
that work needs from the project, such as a worktree per card or a test command.
A [discovery run](workshop.md#run-discovery) reads the lists through the
`workflow_get` MCP tool, and checks the project against them. A project bound
before this release gets the lists through a migration.

## What a request carries

A request for work carries the context of its card, as the card was when the
request opened. The bridge can fill a prompt or a command with each value.

A rule that reads a pull request tries the open pull requests of the card from
the bottom of a stack first, then the oldest opened. It acts on the first one
that makes its condition true. The fix limit counts per pull request: when the
rule moves to another pull request, its count starts again. The count also
starts again while that pull request is healthy: its checks passed, it has no
conflict, and no review asks for changes. A fix that works therefore does not
use up the limit. A card that paused at the fix limit continues on its own when
its pull request turns healthy. The `refill` parameter of the fix rules in the
Lifecycle template sets this. It reads the pull request that the rule acts on,
so a rule whose condition reads no pull request never refills.

| Value | What it holds |
|---|---|
| Pull request number | the number of the pull request that the rule acts on |
| Pull request link | the link to that pull request, as the card holds it |
| Head commit | the head commit of that pull request. A later push leaves it behind |
| Reason | `conflict`, `checks-failed` or `changes-requested`, from the state of that pull request. A `repair` request holds the refusal code of the failed work |
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
