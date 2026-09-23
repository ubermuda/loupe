---
title: "Development lifecycle"
description: "The board columns a card passes, and the bridge rules that run a worker in each stage."
---

A card on the Loupe project board moves through six columns. When a person
moves a card into a column with a worker, `loupe bridge` starts an unattended
`claude -p` worker in this repository. The worker runs the stage skill for that
column, reports one result line, and stops. A person approves each document.

A card move either reports an agent's own state or carries a person's
judgement. An agent makes the first kind and never the second. So the
implementation worker moves its own card to In review, because only it knows
the pull request is ready. Every move that carries an approval stays with the
person who approves.

## Columns

| Label | Slug | Flag | Worker |
|---|---|---|---|
| Backlog | `backlog` | default | none |
| Product design | `product-design` | | `loupe-stage-product-design` |
| Tech design | `tech-design` | | `loupe-stage-tech-design` |
| Implementation | `implementation` | | `loupe-stage-implementation` |
| In review | `in-review` | | none |
| Done | `done` | terminal | none |

A card moves from Implementation to In review when its pull request is ready.
The implementation worker makes that move itself, and the `Board` section of
`.loupe/lifecycle.md` names the column it moves to. After the pull request
merges, the card moves from In review to Done, by hand until a forge webhook
reports the merge.
Pull request fix rounds, for review feedback and failing checks, run while the
card is in In review. No rule starts a worker when a card enters In review.

A new board starts with `next` and `in-progress` columns. What to do with the
cards in those two columns is the owner's call.

A bridge rule names a column by its slug. When a rename changes a slug, every
rule on the old slug stops working.

## Rule file

The rule file is `rules.yaml`. It lives beside `config.json`:
`~/Library/Application Support/loupe/` on macOS, and `$XDG_CONFIG_HOME/loupe/`
or `~/.config/loupe/` on Linux. After a change, run `loupe bridge reload` to
apply the file to the running bridge.

```yaml
projects:
  loupe:
    dir: ~/Code/loupe

rules:
  - name: product-design
    on: board.card_moved
    project: loupe
    to: product-design
    permissionMode: acceptEdits
    prompt: |
      Use the loupe-stage-product-design skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}) entered {to}.
      Loupe instance https://loupe.ac.
      If the card is no longer in {to}, stop.

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
```

The `fix-round` rule starts a document fix round when a person requests changes
on a product or tech design document. The event names the linked card in the
column where the document's stage starts. A document with no such card starts
no worker.

Each prompt carries `{projectId}` and the Loupe instance. The implementation
skill uses both to build the card link in the pull request body. Without the
instance line, the body names `Loupe card <number>` instead. `cli/README.md` describes every field and
placeholder.

Every move and every review verdict in this lifecycle comes from a person. A
person's event resets the chain count of the card, so the `maxChain` cap limits
nothing here.

## Permissions

The design rules and the `fix-round` rule use `acceptEdits`. On one machine, a
probe showed that this mode reaches the Loupe write tools in `claude -p` with
no allow rule. When a worker run reports a denied tool, add an
`mcp__loupe__*` allow rule to `.claude/settings.local.json`.

The implementation rule uses `bypassPermissions`. The gate runs arbitrary
commands, and a worker in `acceptEdits` cannot approve them, because nobody
answers a permission prompt. This choice has a cost. The worker can run any
command as the owner from the moment it starts. The worktree binds file edits,
and it does not bind commands.

## Repository profile and adapters

The stage skills hold the procedure, and three kinds of file hold the values
that change from one setup to the next.

1. The repository profile, `.loupe/lifecycle.md`, belongs to the repository. Its
   sections are `Instruction files`, `Worktree`, `Gate`, `Code review`,
   `Changelog`, `Pull request` and `Board`. The profile of this repository names
   `just cs`, `just ci`, the Codex review, `changelog.d/` and the `in-review`
   column. The slug lives there because a stage skill never reads the column
   list, which can be missing.
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

Run one bridge for this project, with one worker at a time:

```sh
loupe bridge run --max-workers 1
```

Every worktree shares one `php-fpm` container, so two workers that run the gate
at the same time interfere with each other.

## Fix rounds by hand

The `fix-round` rule starts a document fix round. No rule starts a pull request
fix round yet, so run one by hand after review feedback arrives.

1. Wait until no worker runs, on any card. The bridge log shows the end line
   of each worker. Then stop the bridge.
2. Check that no test run is left. Stopping the bridge during a gate kills the
   host process, and PHPUnit keeps running inside the shared `php-fpm`
   container. From the main checkout, this command must print `0`:

```sh
docker compose exec php-fpm ps aux | grep -c phpunit
```

3. Run this from the repository root:

```sh
claude -p --permission-mode bypassPermissions -- "Use the loupe-stage-fix-round skill. Card <number> (cardId <id>) in project loupe (projectId <id>), column in-review. Loupe instance https://loupe.ac."
```

The prompt names the column the card is in. The round answers the review and
the failed checks in In review. A card in Implementation with an open pull
request gets the same round, so use `implementation` for that card.

## Result lines

The final reply of each worker starts with `STAGE RESULT:`. The bridge reports
it, and the worker runs page at `/projects/{id}/worker-runs` shows it.

## Owner checklist

1. Deploy a release with configurable columns. Check that the Loupe MCP lists
   `board_columns`.
2. Read the project slug on the project settings page. When it is not `loupe`,
   change the `projects` key and every `project` field in the rule file.
3. A new board already has `backlog` and `done`. Create the four other
   columns with the slugs above: `product-design`, `tech-design`,
   `implementation` and `in-review`.
4. Write `rules.yaml`.
5. Start the bridge with `loupe bridge run --max-workers 1`.
6. Do one acceptance run with a small card. Move it through Product design,
   an approval, Tech design, an approval and Implementation. Request changes on
   one design document, and check that the `fix-round` rule starts a worker.
   Check that the implementation worker moves the card to In review itself once
   its pull request is ready. Run one pull request fix round there. After the
   merge, move the card to Done by hand. Record the `STAGE RESULT` of each
   worker run on the card.

## What comes later

These pieces are planned after this one. Entries 2 and 3 now have an approved
design, "An automated card lifecycle", and cards on the board.

1. The bridge gets roles and bindings, with one worktree for each card.
2. An approval moves the card, and reaches the agent as its own event.
3. A forge adapter reports reviews, checks and merges to Loupe. The adapter is
   per forge, and the events it emits name no forge.
4. A workspace page shows the work in progress.
5. The lifecycle is packaged for use in other projects.
