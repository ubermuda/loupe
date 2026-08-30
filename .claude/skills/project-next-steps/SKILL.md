---
name: project-next-steps
description: Use when adding, editing, or closing entries in docs/NEXT_STEPS.md, the local open-work tracker. Covers the required entry format (author, type, priority), attribution rules, and the delete-when-resolved discipline.
---

# NEXT_STEPS.md, the open-work tracker

`docs/NEXT_STEPS.md` is the committed tracker for open work only: TODOs,
follow-ups, known issues, and product ideas not yet committed to. It is the ONLY
sanctioned place for such notes. Never put them in code comments. Gamache's
`NoTodosCheck` enforces that side.

## What does not belong

An entry that asks nothing of anyone does not belong here. A decision already
taken, a finding checked and dismissed, a do-not-fix note, or a runbook for a
known-quiet failure is an observation. Observations go in the relevant skill or
in `docs/`, where the person who needs them already reads. In the tracker, every
future scan re-reads and re-dismisses them, and they bury the work that waits.

The test is whether the entry has an addressee. "Someone should do X" is an
entry. "We decided X and here is why" is a skill or a doc. A `Status:` that will
never change means you write the wrong kind of note. Move it, then
cross-reference it from wherever the work would otherwise be rediscovered.

## The repository is public

Every entry is published the moment you push it. Write no secrets, no customer
names, and no venting about people. A later deletion does not unpublish an
entry, because it stays in git history.

## The file is branch content

Git tracks the file, so two consequences follow.

- Edit it in whatever worktree you work in, like any other file.
- Parallel branches that both append land in the same region and conflict.
  Resolve the conflict by keeping both entries. Never take one side: each entry
  is somebody's note, and dropping one makes a tracked item disappear silently.

## Entry format

Every entry is a `##` section: a heading, one metadata line, then a
self-contained body.

```markdown
## Short imperative or descriptive title

**Author:** Geoffrey · **Type:** bug · **Priority:** high · **Status:** pending

Body: what, why it matters, where the relevant code lives (paths), and any
non-obvious close-out steps. Absolute dates only (2026-07-25, never
"yesterday"). The body must make sense to a session with zero context —
which also means **no session-ephemeral identifiers**: feature codenames
("F3"), plan task numbers ("Task 16"), wave/phase labels, or spec section
references are meaningless later. Name the real thing instead (the class,
the route, the file, "the account-deletion work"). Durable, globally
resolvable references (PR numbers, issue links) are fine.
```

The metadata line is mandatory. It comes first after the heading. It holds
exactly four fields, separated by ` · `.

- **Author**: who originated the item, not who typed it. An item the owner
  dictated or decided is `Geoffrey`, even when an agent wrote it down. An item
  an agent found on its own is `Claude`. An unknown or unattributable item is
  also `Claude`, because agents absorb the ambiguity, never the owner.
- **Type**: one of these six values.
  - `feature`: new or extended app/product capability
  - `bug`: something behaves incorrectly today, including latent bugs
  - `security`: exposure, hardening, or credential/access concern
  - `tooling`: dev environment, CI gates, scripts, skills, build
  - `docs`: documentation-only work, including "do not fix" clarifications
  - `idea`: long-horizon product thinking, no commitment yet
- **Priority**: `high` (blocks or degrades real work or users; gate-integrity
  and security exposures default here), `medium` (worth scheduling), or `low`
  (opportunistic, cosmetic, or only-if-it-bites).
- **Status**: `pending` (the default for a new entry) or `in-progress` (a
  branch, worktree, or session works it now; name that in the body). There is
  no `done`, because you delete a resolved entry.

## Lifecycle rules

- Order entries by priority band: all `high` first, then `medium`, then `low`.
  Insert a new entry at the END of its band. When you re-grade an entry's
  priority, move it to its new band.
- Delete a resolved entry entirely. Write no "CLOSED" marker, no resolution
  note, and no archive section. When part of an entry is resolved, rewrite the
  entry to hold only what is still open.
- Keep one concern per entry. Split the entry when its body collects a second
  independent piece of work.
- Cross-reference, do not duplicate. A related entry links by title (see 'Entry
  title').
- An automation candidate is a convention worth enforcing by a gamache rule, a
  lint, or a hook rather than by review. It belongs here as a `tooling` entry
  once it is work someone should pick up. Rawer candidates collect in
  `docs/AUTOMATIONS.md`, which is gitignored and local to one checkout. Point at
  that file only alongside enough detail to stand without it.
- When an entry's premise goes stale (for example "production has no worker"
  after a worker ships), fix the body in place. A tracker that lies is worse
  than none.

## Attribution when appending

An agent that appends on the owner's behalf writes `**Author:** Geoffrey` only
when the item is the owner's explicit note, request, or decision from the
conversation. Everything else is `**Author:** Claude`, including an item that
the owner's question merely inspired.
