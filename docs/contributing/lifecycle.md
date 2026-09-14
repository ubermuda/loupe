---
title: "Development lifecycle"
description: "The board columns a card passes, and the bridge rules that run a worker in each stage."
---

A card on the Loupe project board moves through five columns. When a person
moves a card into a stage column, `loupe bridge` starts an unattended
`claude -p` worker in this repository. The worker runs the stage skill for that
column, reports one result line, and stops. A person approves each document
and moves each card. The worker never moves a card.

## Columns

| Label | Slug | Flag | Worker |
|---|---|---|---|
| Backlog | `backlog` | default | none |
| Product design | `product-design` | | `loupe-stage-product-design` |
| Tech design | `tech-design` | | `loupe-stage-tech-design` |
| Implementation | `implementation` | | `loupe-stage-implementation` |
| Done | `done` | terminal | none |

A new board starts with `next` and `in-progress` columns. What to do with the
cards in those two columns is the owner's call.

A bridge rule names a column by its slug. When a rename changes a slug, every
rule on the old slug stops working.

## Rule file

The rule file is `rules.yaml`. It lives beside `config.json`:
`~/Library/Application Support/loupe/` on macOS, and `$XDG_CONFIG_HOME/loupe/`
or `~/.config/loupe/` on Linux. The bridge reads it at start only, so restart
the bridge after a change.

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
      If the card is no longer in {to}, stop.

  - name: tech-design
    on: board.card_moved
    project: loupe
    to: tech-design
    permissionMode: acceptEdits
    prompt: |
      Use the loupe-stage-tech-design skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}) entered {to}.
      If the card is no longer in {to}, stop.

  - name: implementation
    on: board.card_moved
    project: loupe
    to: implementation
    permissionMode: bypassPermissions
    prompt: |
      Use the loupe-stage-implementation skill.
      Card {cardNumber} (cardId {cardId}) in project {project} (projectId {projectId}) entered {to}.
      If the card is no longer in {to}, stop.
```

Each prompt carries `{projectId}`. The implementation skill uses it to build the
card link in the pull request body. `cli/README.md` describes every field and
placeholder.

Every move in this lifecycle comes from a person, and a person's move resets
the chain count of the card. The `maxChain` cap therefore limits nothing here.

## Permissions

The design rules use `acceptEdits`. On one machine, a probe showed that this
mode reaches the Loupe write tools in `claude -p` with no allow rule. When a
worker run reports a denied tool, add an `mcp__loupe__*` allow rule to
`.claude/settings.local.json`.

The implementation rule uses `bypassPermissions`. The gate runs arbitrary
commands, and a worker in `acceptEdits` cannot approve them, because nobody
answers a permission prompt. This choice has a cost. The worker can run any
command as the owner from the moment it starts. The worktree binds file edits,
and it does not bind commands.

## Run the bridge

Run one bridge for this project, with one worker at a time:

```sh
loupe bridge run --max-workers 1
```

Every worktree shares one `php-fpm` container, so two workers that run the gate
at the same time interfere with each other.

## Fix rounds by hand

No rule starts a fix round yet. Run one by hand after review feedback arrives.

1. Stop the bridge, or wait until no worker runs. Every gate shares one
   `php-fpm` container, so a worker on any card counts.
2. Run this from the repository root:

```sh
claude -p --permission-mode bypassPermissions -- "Use the loupe-stage-fix-round skill. Card <number> (cardId <id>) in project loupe (projectId <id>), column <slug>."
```

The fix round reads the column of the card. In a design column it answers the
document review. In the Implementation column it answers the pull request
review and the failed checks.

## Result lines

The final reply of each worker starts with `STAGE RESULT:`. The bridge reports
it, and the worker runs page at `/projects/{id}/worker-runs` shows it.

## Owner checklist

1. Deploy a release with configurable columns. Check that the Loupe MCP lists
   `board_columns`.
2. Read the project slug on the project settings page. When it is not `loupe`,
   change the `projects` key and every `project` field in the rule file.
3. A new board already has `backlog` and `done`. Create the three stage
   columns with the slugs above.
4. Write `rules.yaml`.
5. Start the bridge with `loupe bridge run --max-workers 1`.
6. Do one acceptance run with a small card. Move it through Product design,
   an approval, Tech design, an approval and Implementation. Run one document
   fix round and one pull request fix round. Move the card to Done by hand.
   Record the `STAGE RESULT` of each worker run on the card.

## What comes later

These pieces are planned after this one.

1. The bridge gets roles and bindings, with one worktree for each card.
2. Automations run when a person approves a document.
3. A GitHub webhook reports reviews, checks and merges to Loupe.
4. A workspace page shows the work in progress.
5. The lifecycle is packaged for use in other projects.
