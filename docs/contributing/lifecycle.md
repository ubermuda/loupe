---
title: "Development lifecycle"
description: "The board columns a card passes, and the work the bridge runs in each stage."
---

A card on the Loupe project board moves through six columns. The board follows
the Lifecycle [workflow template](../using/workflows.md). When a card enters a
slot with work, the workflow opens a work request, and `loupe bridge` runs an
unattended `claude -p` worker in this repository. The worker runs the stage
skill for that kind of work, reports one result line, and stops. Product design
opens an interactive session instead, because the owner writes the product
document with the agent. A person approves each document.

A card move either reports an agent's own state or carries a person's
judgement. An agent makes the first kind and never the second. Every move that
carries an approval stays with the workflow, which reads the approval itself.
The workflow also makes the moves that follow a pull request.

## Columns

| Label | Slug | Flag | Work |
|---|---|---|---|
| Backlog | `backlog` | backlog | none |
| Product design | `product-design` | | `product-design`, `product-design-revise` |
| Tech design | `tech-design` | | `tech-design`, `tech-design-revise` |
| Implementation | `implementation` | | `implement`, `breakdown`, `fix` |
| In review | `in-review` | | `fix`, `merge`, `sync`, `rebase-stacked` |
| Done | `done` | terminal | `teardown` |

A `repair` request belongs to no column. The workflow opens it in any column,
when the work of a rule fails and its retries run out.

The implementation worker ends when it opens the pull request, and it reports
`waiting`. It does not wait for CI. The workflow reads the pull request after
each push. When the required checks pass, it moves the card from
Implementation to In review. When the pull request merges, it moves the card
to Done. A failed check, a conflict or a request for changes asks for a fix. An
approved pull request with green checks asks for the merge, and an approved
branch behind `main` asks for an update. With the merge and sync writes on,
Loupe does both itself, and no worker runs.
[Workflows](../using/workflows.md#the-lifecycle-template) lists every rule.

The implementation worker reads the saved decision answers of the tech design.
An answer wins over the text of the design. The worker lists those decisions at
the top of its plan.

The owner can run `/loupe:product-design` by hand in Claude Code, from a card or
from a one-line idea. The session creates the card in Product design, or moves
an existing card there from an earlier column, such as Backlog or Next. Then it
writes the product document with the owner. The session takes its intake from
the card. When the Claude Design MCP is connected, it recommends one Claude
Design session at the first choice with a visible side. The session asks every
open decision in the chat, and writes a decision fence only for a decision that
the owner defers. The approval of that document moves the card to Tech design.
When a person requests changes on the document, the workflow asks for
`product-design-revise` work, which runs `loupe-stage-fix-round`.

The `product-design` work entry opens the session for you. When a card enters
Product design, the bridge opens a terminal window on its machine that runs
`/loupe:product-design <number>`. The request needs the `interactive`
capability, so only a bridge with that entry takes it. Put the entry on one
machine only. `cli/README.md` describes the entry and the `launch` block it
needs.

A card does not have to pass Product design. Move it from Backlog straight to
Tech design when the card body already says what to build. The tech design
worker then uses the card body as its requirement source. When the card has a
product document that is not approved yet, the worker stops and waits for it.

When the feature changes how production deploys, the tech design worker also
writes a deploy notes document with the tag `deploy-notes`. The implementation
worker revises it when the build changes a deploy item. The deploy agent reads
it before a deploy.

The template links each slot to a column by its id, so a rename of a column
keeps the workflow working.

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
line first. A child with no entry line matches by judgement, from its title and
body against the entry. When a match is unsure, the worker asks the owner with
one `inbox_ask`, writes nothing, and stops with
`STAGE RESULT: blocked: breakdown match needs the owner`. The bridge resumes it
when the ask closes. So the breakdown never creates a duplicate.
`plugins/loupe/skills/loupe-stage-implementation/references/breakdown.md` holds
the full format.

The implementation worker picks one of three modes after it finds the tech
design:

1. Child: the card has a parent. A Breakdown child has the entry line in its
   body. The worker builds only that entry, from the tech design of the epic.
   A Breakdown child skips product design and tech design. A standalone child
   has no entry line, for example a card moved under an epic by hand. The
   worker builds its own approved tech design as a normal card. A standalone
   child that links only the approved tech design of its epic builds its card
   body against that design, and the plan covers only the work that the card
   body describes. With no approved design linked, the worker stops with
   `STAGE RESULT: blocked: needs its own tech design: move the card to Tech design`.
2. Breakdown: the card is an epic, or its tech design has a `Breakdown`
   section. The worker writes no code and changes no file. It sets the type
   `epic`, adds the entry line and the entry to each matched child, links the tech
   design of the epic to each child, creates each missing child in Backlog,
   and sets the blocked-by links. It moves no child. The workflow moves each
   new child to Next, and starts a child that links an approved tech design,
   has no open blocker, and whose epic sits in Implementation. The
   result line is `STAGE RESULT: breakdown <n> children`, and the lines after
   it list each match with its reason.
3. Normal: every other card. The worker builds the whole design into one pull
   request.

An epic never gets a coding worker. An epic that already has children still
gets the breakdown when it enters Implementation. When an epic moves back to
Implementation, its worker runs the breakdown again, finds no missing child,
and stops.

The workflow makes three moves on its own:

1. When the last open blocker of a child in Next finishes, and its epic sits in
   Implementation, the child moves to Implementation, which asks for its
   implementation.
2. When every child of an epic is done, the epic moves to In review when it has
   a pull request, and to Done when it has none. An epic with a child merged
   into its epic branch never moves straight to Done. While a breakdown request is
   active, the epic stays in Implementation. A breakdown that stops to ask the
   owner ends its request, so the epic can move on. A child that the resumed
   breakdown creates then brings the epic back.
3. When a done epic gets an open child again, the epic moves back to
   Implementation.

A child pull request merges into the epic branch, `epic/<number>`. The board
setting **Epic branch pattern** must name that branch. After the first child
merge, the app opens a draft epic pull request from the epic branch to the
default branch, and links it to the epic. The board setting **Open the epic
pull request** turns this on. After each child merge, the workflow asks for
`epic-preview` work on the child. Its worker refreshes the epic preview
`.worktrees/epic-<number>`, and copies the preview links of the merged children
to the epic pull request.

## Rule file

The rule file is `rules.yaml`. It lives beside `config.json`:
`~/Library/Application Support/loupe/` on macOS, and `$XDG_CONFIG_HOME/loupe/`
or `~/.config/loupe/` on Linux. After a change, run `loupe bridge reload` to
apply the file to the running bridge. A file with a `rules:` list no longer
loads.

```yaml
maxWorkers: 1

accounts:
  claude:
    harness: claude-code

defaults:
  account: claude

projects:
  loupe:
    dir: ~/Code/loupe

launch:
  command: ["open", "-a", "Terminal", "{script}"]

work:
  product-design:
    action: interactive
    prompt: /loupe:product-design {cardNumber}

  product-design-revise:
    permissions: workspace
    prompt: |
      Use the loupe-stage-fix-round skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}), column product-design.
      Loupe instance https://loupe.ac.

  tech-design:
    permissions: workspace
    prompt: |
      Use the loupe-stage-tech-design skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}) entered tech-design.
      Loupe instance https://loupe.ac.
      If the card is no longer in tech-design, stop.

  tech-design-revise:
    permissions: workspace
    prompt: |
      Use the loupe-stage-fix-round skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}), column tech-design.
      Loupe instance https://loupe.ac.

  implement:
    permissions: full
    before:
      run: [bin/worktrees/bridge-before.sh, "{cardNumber}", "{cardId}"]
      timeout: 15m
    prompt: |
      Use the loupe-stage-implementation skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}) entered implementation.
      Loupe instance https://loupe.ac.
      If the card is no longer in implementation, stop.

  breakdown:
    permissions: full
    prompt: |
      Use the loupe-stage-implementation skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}) entered implementation.
      Loupe instance https://loupe.ac.
      If the card is no longer in implementation, stop.

  fix:
    permissions: full
    before:
      run: [bin/worktrees/bridge-before.sh, "{cardNumber}", "{cardId}"]
      timeout: 15m
    prompt: |
      Use the loupe-stage-fix-round skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}).
      A pull request of the card needs a fix.
      Loupe instance https://loupe.ac.

  merge:
    permissions: full
    prompt: |
      Use the loupe-stage-merge skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}).
      Loupe asks for merge work.
      Loupe instance https://loupe.ac.

  sync:
    permissions: full
    prompt: |
      Use the loupe-stage-merge skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}).
      Loupe asks for sync work.
      Loupe instance https://loupe.ac.

  epic-preview:
    permissions: full
    prompt: |
      Use the loupe-stage-merge skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}).
      Loupe asks for epic preview work.
      Loupe instance https://loupe.ac.

  repair:
    permissions: full
    before:
      run: [bin/worktrees/bridge-before.sh, "--git-only", "{cardNumber}", "{cardId}"]
      timeout: 15m
    prompt: |
      Use the loupe-stage-repair skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}).
      Rule {ruleId} failed with {reason}.
      Loupe instance https://loupe.ac.

  teardown:
    action: command
    run: [bin/worktrees/bridge-teardown.sh, "{cardNumber}"]
```

The `before` command of the `implement` and `fix` entries makes or refreshes
`.worktrees/card-<number>`, provisions it with `just worktree-up`, and prints
its path. The worker starts in that folder, and the stage skills work there.
The `teardown` entry runs `just worktree-down` with no agent when the card
reaches Done. For an epic, it also removes the epic preview
`.worktrees/epic-<number>`.
[Before command](../extending/cli-bridge.md#before-command) and
[Command action](../extending/cli-bridge.md#command-action) describe both
fields.

The breakdown writes no code, so its entry has no `before` command. Its worker
runs in the main checkout, and changes only the board.

The `epic-preview` entry has no `before` command either. It runs the
`loupe-stage-merge` skill from the main checkout, and the skill works in the
epic preview `.worktrees/epic-<number>`.

The `repair` entry runs the `loupe-stage-repair` skill after the work of a
rule fails and its retries run out. Its `before` command takes `--git-only`, so
it makes or keeps `.worktrees/card-<number>` with git alone, and provisions no
app. The failed step is often the provisioning of the app, so the repair must
not depend on it. The
worker finds the failed run of the rule with `worker_run_list` and
`worker_run_get`. The `Repair` section of `.loupe/lifecycle.md` lists what it
may change: the card branch, the card worktree, and a sync of the epic branch
with `main`. When the repair ends done, the rule gets one last try.
[Workflows](../using/workflows.md#pauses-and-retries) describes the escalation.

The example has no `rebase-stacked` entry, because no stage skill changes the
base of a pull request. Turn on the board setting **Change the base of a pull
request when the workflow asks**, so Loupe changes the base itself. With the
setting off, a request for `rebase-stacked` work waits for a bridge with that
entry, and then pauses the card.

The `fix` round fixes the conflict, each failing check and each open review
item, pushes, and reports `waiting`. A fix that ends `unfinished` pauses the
card, because Lifecycle retries only `failed` and `timeout`. The `merge` and `sync` entries take the one open pull request of the card.
The merge reads the pull request again before it merges: the same head, an
approval, and every required check green. The approval must also cover every
commit after it, read by time. A sync merge from the base passes, with or
without a conflict resolution. Any other later commit stops the run as
`not ready`.

Each prompt carries `{projectId}` and the Loupe instance. The implementation
skill uses both to build the card link in the pull request body. Without the
instance line, the body names `Loupe card <number>` instead. `cli/README.md`
describes every field and placeholder.

## Permissions

The design entries use the `workspace` level, which runs Claude Code in `auto`
mode. When a worker run reports a denied tool, add an `mcp__loupe__*` allow
rule to `.claude/settings.local.json`.

The `implement`, `breakdown`, `fix`, `merge`, `sync` and `repair` entries use
the `full` level, which runs Claude Code in `bypassPermissions` mode. The gate
and the `gh` calls run arbitrary commands, and nobody answers a permission
prompt in a worker. This choice has a cost. The worker can run any
command as the owner from the moment it starts. The worker folder is a separate
tree, but a command can still reach any path on the machine.

## Repository profile and adapters

The stage skills hold the procedure, and three kinds of file hold the values
that change from one setup to the next.

1. The repository profile, `.loupe/lifecycle.md`, belongs to the repository. Its
   sections are `Instruction files`, `Environment`, `Gate`, `Code review`,
   `Changelog`, `Pull request`, `Board`, `Merge` and `Repair`. The profile of this repository names
   `just cs`, the targeted checks, the Codex review, `changelog.d/`, the merge method and
   the column slugs that a stage moves a card to or reads. The slugs live there because a stage
   skill never reads the column list, which can be missing.
2. A harness adapter maps the steps of a worker to the tools of one agent
   harness: connect to Loupe, load an instruction, run a long command,
   dispatch a sub-agent, and write and run a plan. The generic adapter is
   `.agents/skills/loupe-stage-implementation/references/harnesses/generic.md`.
   The compatibility adapter for Claude Code is
   `.agents/skills/loupe-stage-implementation/references/harnesses/claude-code.md`.
   The adapter for Codex is
   `.agents/skills/loupe-stage-implementation/references/harnesses/codex.md`.
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

The `fix` entry runs a pull request fix round. For a repository that the app
does not read, run one by hand after review feedback arrives, when no worker
runs on the card. Set the three values, and run the commands from the main
checkout. The `before` script makes or refreshes the card worktree and prints
its path, and the round runs there:

```sh
card=405 card_id=01a0f4b8-0ec7-7b8e-b4e5-3cd6408919ef pr=710
dir=$(bin/worktrees/bridge-before.sh "$card" "$card_id" "$pr") && cd "$dir" && \
claude -p --permission-mode bypassPermissions -- "Use the loupe-stage-fix-round skill. Card $card (cardId $card_id) in project loupe (projectId <project id>), column in-review. Loupe instance https://loupe.ac."
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

A run that ends `unfinished` refuses its work request. Lifecycle retries only
`failed` and `timeout`, so the workflow pauses the card at once, and the
owner's inbox gets an item. A **Blocked** run puts a warning on its card
until a later run of the card ends another way.

A worker that ends `not ready` or `blocked:` on a pull request posts a refusal
comment there. The comment gives the reason and the next step of a person. A
hidden marker holds the head commit and the reason, so a repeat for the same
head and reason posts nothing. A machine fault that an approver cannot fix,
such as `no worker folder`, posts no comment. The worker leaves the
comment in place when the block clears.

## Owner checklist

1. Deploy a release with configurable columns. Check that the Loupe MCP lists
   `board_columns`.
2. Read the project slug on the project settings page. When it is not `loupe`,
   change the `projects` key in the rule file.
3. A new board already has `backlog` and `done`. Create the four other
   columns with the slugs above: `product-design`, `tech-design`,
   `implementation` and `in-review`.
4. Write `rules.yaml`.
5. Check that `rules.yaml` starts with `maxWorkers: 1`, then start the bridge
   with `loupe bridge run`.
6. Do one acceptance run with a small card. Start it with
   `/loupe:product-design`, then take it through an approval, Tech design, an
   approval and Implementation. Request changes on one design document, and
   check that a revise worker starts. Check that the implementation worker
   reports `waiting` once its pull request is open, and that the workflow moves
   the card to In review on green checks. Approve the pull request, and check
   that it merges and that the workflow moves the card to Done. Record the
   `STAGE RESULT` of each worker run on the card.

## What comes later

These pieces are planned after this one. Entry 2 has an approved design, "An
automated card lifecycle", and cards on the board. Entry 3 has shipped for
GitHub.

1. The bridge gets roles and bindings.
2. An approval moves the card, and reaches the agent as its own event.
3. A forge adapter reports reviews, checks and merges to Loupe. The adapter is
   per forge, and the events it emits name no forge.
4. A workspace page shows the work in progress.
5. The lifecycle is packaged for use in other projects.
