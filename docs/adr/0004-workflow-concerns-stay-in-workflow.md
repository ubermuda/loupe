---
title: "0004: Keep workflow decisions in the workflow template, not in app code"
description: "When work happens, and which work a card needs, is a rule of the workflow template. The app runs the rules and does not make those decisions itself."
---

## Status

Proposed on 2026-10-09.

## Context

Loupe has three parts that act on a card:

- The workflow template, such as `config/workflows/lifecycle.yaml`, holds the rules. A rule has a condition and an action: move the card, ask a bridge for work, write to the forge, or ask a person.
- The app holds the workflow engine. The engine reads facts about a card and its pull requests, evaluates the rules, and runs the action of each rule that fires.
- The bridge runs the work that a rule asks for, as ADR 0002 says.

A workflow decision answers one of these questions: when does work happen, which work does a card need, in what order, and how often does it retry. These decisions have a home in the template. When app code makes one of them, the decision leaks out of the workflow. Nobody can read it on the **Workflow** page, it differs from the template, and a change to the process needs a change to PHP.

The question came up on 2026-10-09. The epic previews of epic 390 and epic 611 served old commits after fix rounds pushed to their branches. The `epic-preview` rule fires only when a child merges into the epic branch, so other pushes ask for no preview work. One proposed fix made the app start a refresh on each push to an epic branch. That fix is correct about when to refresh, and wrong about where the decision lives.

## Decision

Every workflow decision is a rule of the workflow template. The app evaluates the rules and runs their actions, and it makes no workflow decision of its own.

The rule in detail:

- The app may add a fact that a condition reads, such as `pr.behind` or `card.site_review.check_stale`. A fact says what is true. It does not say what to do about it.
- The app may add an action that a rule runs, such as `forge-write`. An action says how to do a step. It does not say when to do it.
- The app must not start work, move a card or write to the forge because of an event, except through a rule that fires.
- When a needed condition does not exist, add a fact to the engine, and write the rule in the template. Do not add a shortcut in a handler, a listener or a scheduled job.
- A skill or a bridge rule must not make a workflow decision either. A skill does the work of one request. A bridge rule decides how to run the work, never when.

Teardown already follows this split. The `teardown` rule of the template asks for teardown work when a card reaches a terminal slot. The bridge `action: command` rule of ADR 0002 only runs the script. ADR 0002 calls it "a command rule on a terminal column", and the terminal column is the condition of the template rule.

This does not change ADR 0001. ADR 0001 says which part does a step: the app for a mechanical step, a worker for a step that needs judgment. This record says which part decides when the step runs, and that is always the template.

For the epic preview, the fix is a template rule that asks for preview work when the head of the epic branch moves. It needs a fact that compares that head with the head the preview last served. `card.site_review.check_stale` is the nearest example, because it compares a stored value with the head commit of a pull request. The shape of that fact is still open.

## Rejected options

- Let the app react to an event when the template has no condition for it: this is faster to ship. But the template stops being a full description of the process, and two places decide when work runs.
- Let a stage skill take a step that the workflow did not ask for, such as a preview refresh after a fix round: no engine change is needed. But the step runs only in that skill, and a sync or a hand push misses it.
- Keep the decision in the bridge rule file: the owner can change it at once. But the file is local to one machine, and the app cannot read it, so the board and the **Workflow** page cannot show it.

## Consequences

Better:

- The template and the **Workflow** page show the whole process. A reader finds each decision in one place.
- A change to the process is a change to the template, next to the other rules that it can interact with.
- The engine holds the state of each rule, such as its retries and its pauses. A decision that runs outside the engine gets none of that.

Worse:

- A new need often costs a new fact in the engine, with its own state and tests, before the rule can exist. A shortcut in a handler is cheaper on the day.
- The template grows, and it becomes harder to read as one file.
- The **Workflow** page is read-only today, so a project still cannot change a rule without a release.

Watch for app code that starts work, moves a card or writes to the forge outside the action of a rule. Each case is a decision that leaked. Move it into the template, or raise a card to move it.
