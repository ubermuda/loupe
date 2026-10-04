---
title: "0003: Replace the old shape when a requirement changes it, and keep no legacy path"
description: "A refactor for a new requirement moves every user of a system to the new shape and deletes the old one in the same piece of work."
---

## Status

Accepted on 2026-10-04.

## Context

Loupe grows by changing systems that already work. A new requirement often shows that an existing shape is too narrow. The shape can be a table, an entity, an API, the protocol between the app and the bridge, a config file such as `rules.yaml`, an MCP tool, a skill or a code path.

Card 464 is the example. A work request and a worker run must now name a subject that is not a card, such as an analysis, but three tables hold a `card_id` column that is NOT NULL. The first tech design added `subject_type` and `subject_id`, and kept `card_id` filled for a card subject. The only reason was to leave the card queries unchanged. That design stored one fact twice, and needed a check constraint to keep the two in line. It also kept `cardId` in the work request that the bridge receives, so that an older bridge would still work.

A kept field or path of this kind does not stay small. Each later user must choose between the old shape and the new one, and some choose the old one. The old shape then cannot be removed without a second refactor, which costs more than the first one would have.

## Decision

When a new requirement changes a system, replace the old shape. Move every user of the system to the new shape, and delete the old fields, code paths, names and compatibility layers in the same piece of work.

The rule in detail:

- Do not keep a column, a field, a method, a parameter or a code path only so that existing code does not have to change. Rewrite that code.
- Do not store one fact in two places. When a new field covers an old one, the old one goes.
- Change both sides of a contract between two parts of Loupe together. The app, the bridge, the plugin and the skills ship from this repository, so an outdated copy updates. By default, do not keep a compatibility field for it. This is a default, not a hard rule. A person can keep compatibility for one change, for example to protect the runs in flight during a deploy. The design then records that choice, who made it, and the entry that removes the field.
- A label that a person reads, such as a card number, is not a duplicate of an id. It can stay when the new shape still shows it.
- A rolling deploy can need a short transition, because an old image and a new image run at the same time. A transition is allowed only when the old image really reads or writes the old field during the deploy. The step that removes the field runs after no old image is left. It is a named entry of the same plan or breakdown, never a later card.
- A design or a plan that keeps an old path must say why, and name the entry that removes it.

Apply this rule when you write a tech design, a plan or a refactor. When a review finds a kept field or a kept path with no removal entry, treat it as a defect of the design.

## Rejected options

- Keep the old field filled beside the new one: fewer files change, and the change is smaller to review. But the data is stored twice, a constraint must keep it in line, and the old field stays for good.
- Keep the old field and remove it in a later card: the first change ships sooner. But the later card waits behind feature work, and users of the old field grow while it waits.
- Hide the old field behind an accessor that reads the new one: the callers do not change. But the old name stays in the code, and the next reader cannot tell which name is current.
- Keep a compatibility field for an older bridge or plugin: a user who has not updated keeps working. But every later change must test the old copy too, and the field never has a day when it is safe to remove.

## Consequences

Better:

- Each fact has one home. A reader cannot pick the wrong field.
- The code shows the current shape only. A new session reads one shape, not two.
- No refactor leaves a second refactor behind it.

Worse:

- A refactor touches more files, and its pull request is larger. The tests must cover each user that moves, before and after.
- A change to a live table can need a two-step migration for a rolling deploy. The plan must carry both steps.
- An outdated bridge or plugin fails until it updates. The release notes must say so.
- A design takes longer to write, because it must name every user of the old shape.

Watch for a design that keeps an old field or path "for now". Ask for the entry that removes it, and refuse the design if it has none.
