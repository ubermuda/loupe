---
title: "0003: Replace the old model when a requirement changes it, and keep no legacy path"
description: "A refactor for a new requirement moves every reader to the new model and deletes the old one in the same piece of work."
---

## Status

Accepted on 2026-10-04.

## Context

Loupe grows by changing systems that already work. A new requirement often shows that an existing model is too narrow. Card 464 is the example. A work request and a worker run must now name a subject that is not a card, such as an analysis, but three tables hold a `card_id` column that is NOT NULL.

The tech design of card 464 first added `subject_type` and `subject_id`, and kept `card_id` filled for a card subject. The only reason was to leave the card queries unchanged. That design stores one fact twice. A card job holds its card in `card_id` and in `subject_id`, and a check constraint must keep the two in line. `card_id` is a plain value with no foreign key, so it adds no safety that `subject_id` does not give.

A kept column of this kind does not stay small. Each later reader must choose between the old field and the new one, and some choose the old one. The old model then cannot be removed without a second refactor, which costs more than the first one would have.

## Decision

When a new requirement changes a model, replace the old model. Move every reader and writer to the new model, and delete the old fields, code paths and names in the same piece of work.

The rule in detail:

- Do not keep a column, a field, a method or a code path only so that existing code does not have to change. Rewrite that code.
- Do not store one fact in two places. When a new field covers an old one, the old one goes.
- A label that a person reads, such as a card number, is not a duplicate of an id. It can stay when the new model still shows it.
- A rolling deploy can need a short transition, because an old image and a new image run at the same time. A transition is allowed only when the old image really reads or writes the old field during the deploy. The step that removes the field runs after no old image is left. It is a named entry of the same plan or breakdown, never a later card.
- A design or a plan that keeps an old path must say why, and name the entry that removes it.

Apply this rule when you write a tech design, a plan or a refactor. When a review finds a kept field or a kept path with no removal entry, treat it as a defect of the design.

## Rejected options

- Keep the old field filled beside the new one: fewer files change, and the change is smaller to review. But the data is stored twice, a constraint must keep it in line, and the old field stays for good.
- Keep the old field and remove it in a later card: the first change ships sooner. But the later card waits behind feature work, and readers of the old field grow while it waits.
- Hide the old field behind an accessor that reads the new one: the callers do not change. But the old name stays in the code, and the next reader cannot tell which name is current.

## Consequences

Better:

- Each fact has one home. A reader cannot pick the wrong field.
- The code shows the current model only. A new session reads one shape, not two.
- No refactor leaves a second refactor behind it.

Worse:

- A refactor touches more files, and its pull request is larger. The tests must cover each reader that moves, before and after.
- A change to a live table can need a two-step migration for a rolling deploy. The plan must carry both steps.
- A design takes longer to write, because it must name every reader of the old model.

Watch for a design that keeps an old field "for now". Ask for the entry that removes it, and refuse the design if it has none.
