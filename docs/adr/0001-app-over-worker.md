---
title: "0001: Let the app do mechanical work, not a bridge worker"
description: "When a step needs no judgment, an action of the Loupe app does it, rather than an agent that a bridge starts. A rule of the workflow template decides when it runs."
---

## Status

Accepted on 2026-09-30. Amended on 2026-10-09 by [ADR 0004](0004-workflow-concerns-stay-in-workflow.md), which says that the workflow template decides when a step runs.

## Context

The board lifecycle runs two kinds of automation. The workflow engine of the Loupe app evaluates the rules of the workflow template on each change, and runs their actions in PHP. A `loupe bridge` on the owner's machine starts a Claude worker when a rule in its `rules.yaml` matches an event, and the worker follows a stage skill.

Some worker stages do work that needs no judgment. The Update step of `loupe-stage-merge` is the example that raised this record. It reads the review, checks that the approval covers the head, checks that the branch does not conflict, and calls update-branch. Each step is a fixed read or a fixed call.

A worker costs more than the same step in the app:

- Cost: each run starts a Claude session, loads the skill and its references, and spends tokens to reach a result that a few lines of PHP compute for free. A run that ends as `not ready` costs as much as one that acts. On 2026-09-29, pull request #667 got three `merge-behind` runs before anyone reviewed it.
- Performance: a worker waits for a slot in its pool, then needs about 30 s to start, read and act. The `sync-approved` run of #670 on 2026-09-30 took 31 s. The app acts in the request that delivers the webhook, or in the next message on the `async` transport.

A worker also needs a bridge that runs. An instance with no bridge, or a bridge that is paused, asleep or out of usage, does none of that work.

## Decision

When a step needs no judgment, an action of the app does it. A bridge worker does only the work that needs an agent. In both cases, a rule of the workflow template decides when the step runs, as ADR 0004 says. This record decides who does a step, never when it runs.

A step needs no judgment when you can write it as a fixed procedure: read these values, compare them, make this call. When the steps depend on reading and understanding content, the work belongs to a worker. These examples need an agent:

- Write or change code, tests or documents.
- Fix a failing check, whose cause the worker reads from a log.
- Resolve a merge conflict.
- Answer a review comment.
- Write a design or a plan.

These examples do not need an agent:

- Sync a branch that is behind. The `update-behind` rule decides when, and the `forge-write` action calls update-branch.
- Move a card. A rule decides when, such as after a merge or a green check, and its `move` action moves the card.
- Post a fixed comment, or record a state change, as the action of a rule.

Apply this rule when you design a new stage, a new bridge rule or a new automation. When you find an existing worker step that needs no judgment, raise a card to move it into an app action that a template rule runs. Card 369 moved the branch sync this way. The owner applies this preference case by case. A step that the app cannot do yet, such as one that needs a git checkout, can stay in a worker until it can.

## Rejected options

- A worker for every lifecycle step: one mechanism is simpler to learn, and a skill is faster to change than PHP. It spends tokens and half a minute on each event, including the events that end with no work.
- The app for every lifecycle step: the app cannot write code, read a failing log or resolve a conflict. Those steps need an agent.
- A cheaper model for the mechanical worker stages: it lowers the token cost, but the start time, the pool slot and the need for a running bridge stay.

## Consequences

Better:

- Token cost drops, and it no longer grows with the number of events that start no real work.
- A mechanical step takes seconds instead of half a minute, and uses no worker slot. The slots stay free for work that needs an agent.
- The step works on an instance with no bridge, and while the bridge is down.
- The step is PHP with PHPUnit tests, so its behaviour is exact and repeatable. A worker can read a skill in a different way on each run.

Worse:

- The app gets more code to write, test and maintain. A change to a skill is a Markdown edit, while the same change in the app is a pull request through the full gate.
- An app step that writes to a forge needs a forge permission, and a per-project setting that is off by default, as the egress rule in `AGENTS.md` says.
- Some checks are easy with a git checkout and hard without one. The approval check of `loupe-stage-merge` proves that a later commit is a clean sync by re-creating the merge with git. The app has to prove it another way, or accept a stricter rule.

Watch for a worker stage that keeps a mechanical step only because the skill already does it. That is the case this record exists to catch.
