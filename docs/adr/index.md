---
title: "Decision records"
description: "The architecture decisions of Loupe, one record per decision."
---

A decision record keeps one architecture decision, the reasons for it, and what it costs. Read the records before you propose a design that reverses one.

Each record is a file `NNNN-short-name.md` in `docs/adr/`. The number counts from 0001 and never repeats. A record that a later decision replaces keeps its file, and its status names the record that replaces it.

A record has these sections:

- Status: Proposed, Accepted, or Superseded by a later record.
- Context: the problem, and the facts that force a decision.
- Decision: what we do, in one or two sentences, then the rule in detail.
- Rejected options: the options we did not choose, and why.
- Consequences: what gets better, what gets worse, and what to watch.

[Architectural priorities](../contributing/architectural-priorities.md) ranks correctness, simplicity, performance and shipping speed. A record applies that ranking to one recurring question.

## Records

- [0001: Let the app do mechanical work, not a bridge worker](0001-app-over-worker.md)
- [0002: Keep the stage skills generic, and let bridge rules set up the environment](0002-generic-stage-skills.md)
- [0003: Replace the old shape when a requirement changes it, and keep no legacy path](0003-replace-dont-keep.md)
- [0004: Let the workflow keep a preview current](0004-preview-freshness-in-workflow.md)
