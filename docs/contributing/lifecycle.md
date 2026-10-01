---
title: "Development lifecycle"
description: "The board columns a card passes, and the bridge rules that run a worker in a stage."
---

A card on the Loupe project board moves through six columns. When a person
moves a card into a column with a worker, `loupe bridge` starts an unattended
`claude -p` worker in this repository. The worker runs the stage skill for that
column, reports one result line, and stops. Product design has no worker,
because the owner writes the product document in an interactive session. A
bridge rule with `action: interactive` can open that session in a terminal when
the card enters Product design. A person approves each document.

A card move either reports an agent's own state or carries a person's
judgement. An agent makes the first kind and never the second. Every move that
carries an approval stays with the person who approves. The app makes the moves
that follow a pull request, because it reads the pull request itself.

## Columns

| Label | Slug | Flag | Worker |
|---|---|---|---|
| Backlog | `backlog` | backlog | none |
| Product design | `product-design` | | none |
| Tech design | `tech-design` | | `loupe-stage-tech-design` |
| Implementation | `implementation` | | `loupe-stage-implementation` |
| In review | `in-review` | | none |
| Done | `done` | terminal | none |

The implementation worker ends when it opens the pull request, and it reports
`waiting`. It does not wait for CI. The app reads the pull request after each
push. When the required checks pass, the app moves the card from Implementation
to In review. When the pull request merges, the app moves the card to Done.
Only a repository connected through the GitHub App gets these moves, as
[the board page](../using/board.md#what-github-tells-a-card) says.

Four pull request rules start a worker from the events of the app. A failed
check, a conflict or a request for changes starts a fix round. An approved pull
request with green checks starts the merge. An approved branch behind `main`
starts an update. No rule starts a worker when a card enters In review.

The owner runs `/loupe:product-design` by hand in Claude Code, from a card or
from a one-line idea. The session creates the card in Product design, or moves
an existing card there from an earlier column, such as Backlog or Next. Then it
writes the product document with the owner. The session takes its intake from
the card, and offers Claude Design on a look-and-feel choice when the Claude
Design MCP is connected. The approval of that document moves
the card to Tech design. When a person requests
changes on the document, the `fix-round` rule starts `loupe-stage-fix-round`,
which answers the review round. Delete any `product-design` worker rule from
your `rules.yaml`, then run `loupe bridge reload`.

A bridge rule with `action: interactive` on `to: product-design` opens the
session for you. When a person moves a card into Product design, the bridge
opens a terminal window on its machine that runs
`/loupe:product-design <number>`. The owner can still start the session by
hand. Set `card: { interactiveRun: false }` on the rule, so that a session that
moves its own card there opens no second window. Put the rule on one machine
only. `cli/README.md` describes the rule and the `launch` block it needs.

A card does not have to pass Product design. Move it from Backlog straight to
Tech design when the card body already says what to build. The tech design
worker then uses the card body as its requirement source. When the card has a
product document that is not approved yet, the worker stops and waits for it.

A new board starts with `next` and `in-progress` columns. What to do with the
cards in those two columns is the owner's call.

A bridge rule names a column by its slug. When a rename changes a slug, every
rule on the old slug stops working.

## Epics and the breakdown

A card that is too big for one worker becomes an epic with child cards. The
tech design worker judges the size. When the design needs more than one pull
request of normal size, the design gets a `Breakdown` section. Otherwise it
gets none.

Each entry of the section is one child card, with a stable ID, a title, what it
covers, and its blockers:

```markdown
1. **B3: Add swimlanes to the board.** Covers D5 and D6. Blocked by: B1, B2.
```

An ID never changes across revisions. The text after the ID is the title of the
child. The entry line at the top of the child's body names its entry:
`Breakdown item B3 of card #214.` A rerun matches an existing child by that
line, then by the exact title, so it never creates a duplicate.
`plugins/loupe/skills/loupe-stage-implementation/references/breakdown.md` holds
the full format.

The implementation worker picks one of three modes after it finds the tech
design:

1. Child: the card has a parent. A Breakdown child has the entry line in its
   body. The worker builds only that entry, from the tech design of the epic.
   A Breakdown child skips product design and tech design. A standalone child
   has no entry line, for example a card moved under an epic by hand. The
   worker builds its own approved tech design as a normal card. With no such
   design, it stops with
   `STAGE RESULT: blocked: needs its own tech design: move the card to Tech design`.
2. Breakdown: the card is an epic, or its tech design has a `Breakdown`
   section. The worker writes no code and creates no worktree. It sets the type
   `epic`, creates each missing child in Backlog, and sets the blocked-by
   links. Then it moves each child with no open blocker to Implementation. The
   result line is `STAGE RESULT: breakdown <n> children, <m> started`.
3. Normal: every other card. The worker builds the whole design into one pull
   request.

An epic never gets a coding worker. When an epic moves back to Implementation,
its worker runs the breakdown again, finds no missing child, and stops. That
rerun also starts a child that a person put back in Backlog on purpose, when
the child has no open blocker. A parked child stays in Backlog.

The app makes three moves on its own, with the actor `system`:

1. When a card enters a terminal column, each child it blocks moves from
   Backlog to Implementation, once all of that child's blockers are done. The
   move starts an implementation worker for the child.
2. When every child of an epic is done, the epic moves to the first terminal
   column.
3. When a child of a done epic leaves Done, or an open card joins it, the epic
   moves back to Implementation.

A deleted blocks link starts nothing. A child whose last blocker link is gone
waits in Backlog until a person moves it.

## Rule file

The rule file is `rules.yaml`. It lives beside `config.json`:
`~/Library/Application Support/loupe/` on macOS, and `$XDG_CONFIG_HOME/loupe/`
or `~/.config/loupe/` on Linux. After a change, run `loupe bridge reload` to
apply the file to the running bridge.

```yaml
maxWorkers: 1

projects:
  loupe:
    dir: ~/Code/loupe

rules:
  - name: tech-design
    on: board.card_moved
    project: loupe
    to: tech-design
    permissionMode: acceptEdits
    prompt: |
      Use the loupe-stage-tech-design skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}) entered {to}.
      Loupe instance https://loupe.ac.
      If the card is no longer in {to}, stop.

  - name: implementation
    on: board.card_moved
    project: loupe
    to: implementation
    permissionMode: bypassPermissions
    prompt: |
      Use the loupe-stage-implementation skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}) entered {to}.
      Loupe instance https://loupe.ac.
      If the card is no longer in {to}, stop.

  - name: fix-round
    on: document.review_submitted
    project: loupe
    verdict: changes-requested
    permissionMode: acceptEdits
    prompt: |
      Use the loupe-stage-fix-round skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}), column {column}.
      A person requested changes on document {documentId}.
      Loupe instance https://loupe.ac.
      If the card is no longer in {column}, stop.

  - name: fix-pr
    on: pull_request.fix_requested
    project: loupe
    resume: true
    permissionMode: bypassPermissions
    prompt: |
      Use the loupe-stage-fix-round skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}).
      Pull request {pullRequestUrl} needs a fix: {reason}.
      Loupe instance https://loupe.ac.

  - name: merge-ready
    on: pull_request.ready_to_merge
    project: loupe
    permissionMode: bypassPermissions
    prompt: |
      Use the loupe-stage-merge skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}).
      Pull request {pullRequestUrl} is ready to merge at {headSha}.
      Loupe instance https://loupe.ac.

  - name: sync-behind
    on: pull_request.behind
    project: loupe
    permissionMode: bypassPermissions
    prompt: |
      Use the loupe-stage-merge skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}).
      Pull request {pullRequestUrl} is behind its base.
      Loupe instance https://loupe.ac.

  - name: sync-approved
    on: pull_request.review_submitted
    project: loupe
    when:
      verdict: approved
    permissionMode: bypassPermissions
    prompt: |
      Use the loupe-stage-merge skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}).
      Pull request {pullRequestUrl} is behind its base.
      Loupe instance https://loupe.ac.
```

The `fix-round` rule starts a document fix round when a person requests changes
on a product or tech design document. The event names the linked card in the
column where the document's stage starts. A document with no such card starts
no worker.

The `fix-pr` rule resumes the session that built the branch, when the event
names one, and starts a new session otherwise. The round fixes the conflict,
each failing check and each open review item, pushes, and reports `waiting`.
The `merge-ready` rule merges only after it reads the pull request again: the
same head, an approval, and every required check green. The approval must also
cover every commit after it, read by time. A sync merge from the base passes. A
conflict resolution or any other later commit stops the run as `not ready`.
Loupe sends `ready_to_merge` without a look at the review, so the skill makes
this check. The `sync-behind` rule
updates the branch on GitHub with a merge commit, and reports `waiting`. It
updates only a pull request whose approval covers the head, because each update
costs a full CI run. An unapproved branch stops as `not ready`. Loupe sends
`behind` once, when the branch falls behind, so the `sync-approved` rule runs
the same update when the owner approves. A branch that is not behind then stops
as `not ready`. Every
pull request rule acts only on a card that links the pull request.

Each prompt carries `{projectId}` and the Loupe instance. The implementation
skill uses both to build the card link in the pull request body. Without the
instance line, the body names `Loupe card <number>` instead. `cli/README.md` describes every field and
placeholder.

Every review verdict in this lifecycle comes from a person. A person's event
resets the chain count of the card. A `system` move neither counts toward the
chain nor resets it. The breakdown moves each child once, as an agent, so that
move counts one run in the chain of the child. The `maxChain` cap therefore
limits nothing here.

## Permissions

The `tech-design` rule and the `fix-round` rule use `acceptEdits`. On one machine, a
probe showed that this mode reaches the Loupe write tools in `claude -p` with
no allow rule. When a worker run reports a denied tool, add an
`mcp__loupe__*` allow rule to `.claude/settings.local.json`.

The implementation rule and the three pull request rules use
`bypassPermissions`. The gate and the `gh` calls run arbitrary
commands, and a worker in `acceptEdits` cannot approve them, because nobody
answers a permission prompt. This choice has a cost. The worker can run any
command as the owner from the moment it starts. The worktree binds file edits,
and it does not bind commands.

## Repository profile and adapters

The stage skills hold the procedure, and three kinds of file hold the values
that change from one setup to the next.

1. The repository profile, `.loupe/lifecycle.md`, belongs to the repository. Its
   sections are `Instruction files`, `Worktree`, `Gate`, `Code review`,
   `Changelog`, `Pull request`, `Board` and `Merge`. The profile of this repository names
   `just cs`, `just ci`, the Codex review, `changelog.d/`, the merge method and
   the column slugs that a stage moves a card to or reads. The slugs live there because a stage
   skill never reads the column list, which can be missing.
2. A harness adapter maps the steps of a worker to the tools of one agent
   harness: connect to Loupe, load an instruction, bind writes to a worktree,
   run a long command, dispatch a sub-agent, and write and run a plan. The
   generic adapter is
   `.agents/skills/loupe-stage-implementation/references/harnesses/generic.md`.
   The compatibility adapter for Claude Code is
   `.agents/skills/loupe-stage-implementation/references/harnesses/claude-code.md`.
3. A forge adapter maps the pull request operations to the commands of one
   forge. The adapter for GitHub is
   `.agents/skills/loupe-stage-implementation/references/forges/github.md`.

When the profile, one of its sections, or a forge adapter is missing, the
worker stops with a `STAGE RESULT: blocked:` line that names it. A harness with
no dedicated adapter uses the generic adapter.

## Run the bridge

Run one bridge for this project, with one worker at a time. The
`maxWorkers: 1` line at the top of the rule file above sets that bound:

```yaml
maxWorkers: 1
```

Then start the bridge:

```sh
loupe bridge run
```

Every worktree shares one `php-fpm` container, so two workers that run the gate
at the same time interfere with each other.

## Fix rounds by hand

The `fix-pr` rule starts a pull request fix round. For a repository that the app
does not read, run one by hand after review feedback arrives. Run it from the
repository root, when no worker runs on the card:

```sh
claude -p --permission-mode bypassPermissions -- "Use the loupe-stage-fix-round skill. Card <number> (cardId <id>) in project loupe (projectId <id>), column in-review. Loupe instance https://loupe.ac."
```

The prompt names the column the card is in. A card in Implementation with an
open pull request gets the same round, so use `implementation` for that card.

## Worker results

The final reply of each worker starts with `STAGE RESULT:`. The bridge does not
read that line. It asks each worker for a structured result, with a `status` of
`finished`, `blocked`, `unfinished` or `waiting` and a one-sentence `summary`. The worker
sets `status` from its `STAGE RESULT:` form, as the table in
`plugins/loupe/skills/loupe-stage-product-design/references/stage-contract.md`
says. The **Runs** tab of the Activity page, at `/projects/{id}/worker-runs`,
shows the status and the summary.

The bridge resumes an `unfinished` run, a run with no structured result, and a
failed run, up to the rule's `maxResumes`, two by default. A run at that cap
shows **Gave up**. A **Gave up** or **Blocked** run puts a warning on its card
until a later run of the card ends another way, or the card moves.

## Owner checklist

1. Deploy a release with configurable columns. Check that the Loupe MCP lists
   `board_columns`.
2. Read the project slug on the project settings page. When it is not `loupe`,
   change the `projects` key and every `project` field in the rule file.
3. A new board already has `backlog` and `done`. Create the four other
   columns with the slugs above: `product-design`, `tech-design`,
   `implementation` and `in-review`.
4. Write `rules.yaml`.
5. Check that `rules.yaml` starts with `maxWorkers: 1`, then start the bridge
   with `loupe bridge run`.
6. Do one acceptance run with a small card. Start it with
   `/loupe:product-design`, then take it through an approval, Tech design, an
   approval and Implementation. Request changes on
   one design document, and check that the `fix-round` rule starts a worker.
   Check that the implementation worker reports `waiting` once its pull request
   is open, and that the app moves the card to In review on green checks.
   Approve the pull request, and check that the `merge-ready` rule merges it
   and that the app moves the card to Done. Record the `STAGE RESULT` of each
   worker run on the card.

## What comes later

These pieces are planned after this one. Entry 2 has an approved design, "An
automated card lifecycle", and cards on the board. Entry 3 has shipped for
GitHub.

1. The bridge gets roles and bindings, with one worktree for each card.
2. An approval moves the card, and reaches the agent as its own event.
3. A forge adapter reports reviews, checks and merges to Loupe. The adapter is
   per forge, and the events it emits name no forge.
4. A workspace page shows the work in progress.
5. The lifecycle is packaged for use in other projects.
