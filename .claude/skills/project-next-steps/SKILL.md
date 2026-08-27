---
name: project-next-steps
description: Use when adding, editing, or closing entries in docs/NEXT_STEPS.md — the local open-work tracker. Covers the required entry format (author, type, priority), attribution rules, and the delete-when-resolved discipline.
---

# NEXT_STEPS.md — the open-work tracker

`docs/NEXT_STEPS.md` is the committed tracker for **open work only**: TODOs,
follow-ups, known issues, product ideas not yet committed to. It is the ONLY
sanctioned place for such notes — never in code comments (gamache's
`NoTodosCheck` enforces that side).

**An entry that asks nothing of anyone does not belong here.** A decision
already taken, a finding checked and dismissed, a do-not-fix note, a runbook for
a known-quiet failure — these are observations, and observations live in the
relevant skill or in `docs/`, where the person who needs them will be reading
anyway. Putting them in the tracker means every future scan of the file re-reads
and re-dismisses them, and it buries the work that is actually waiting.

The test is whether the entry has an addressee. "Someone should do X" is an
entry. "We decided X and here is why" is a skill or a doc. When you catch
yourself writing a `Status:` that will never change, you are writing the wrong
kind of note — move it, and cross-reference from wherever the work would
otherwise have been rediscovered.

**The repository is public, so every entry is published the moment it is
pushed.** Write accordingly: no secrets, no customer names, no venting about
people. Deleting an entry later does not unpublish it — it stays in git history.

**It is tracked, so it is branch content.** Two consequences:

- Edit it in whatever worktree you are working in, like any other file. It used
  to be gitignored, which meant it existed only in the main checkout — and a
  session bound to a worktree could not write it with `Edit` at all.
- Parallel branches that both append land in the same region and **conflict**.
  Resolve by keeping both entries, never by taking one side: each is somebody's
  note, and "resolved" the conflict by dropping one is how a tracked item
  silently disappears.

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

The metadata line is mandatory, first after the heading, exactly four fields
separated by ` · `:

- **Author** — who originated the item, not who typed it. An item the owner
  dictated or decided is `Geoffrey` even when an agent wrote it down. An item
  an agent discovered on its own is `Claude`. **Unknown or unattributable →
  `Claude`** (agents absorb the ambiguity, never the owner).
- **Type** — one of:
  - `feature` — new or extended app/product capability
  - `bug` — something behaves incorrectly today (including latent bugs)
  - `security` — exposure, hardening, or credential/access concern
  - `tooling` — dev environment, CI gates, scripts, skills, build
  - `docs` — documentation-only work, including "do not fix" clarifications
  - `idea` — long-horizon product thinking, no commitment yet
- **Priority** — `high` (blocks or degrades real work/users; gate-integrity
  and security exposures default here), `medium` (worth scheduling), `low`
  (opportunistic / cosmetic / only-if-it-bites).
- **Status** — `pending` (default for new entries) or `in-progress` (a branch,
  worktree, or session is actively working it — name it in the body when
  setting this). There is no `done`: resolved entries are deleted.

## Lifecycle rules

- **Entries are ordered by priority band** — all `high` first, then `medium`,
  then `low`. Insert a new entry at the END of its band; when re-grading an
  entry's priority, move it to its new band.
- **Delete resolved entries entirely.** No "CLOSED" markers, no resolution
  notes, no archive section. If part of an entry is resolved, rewrite it to
  contain only what is still open.
- **One concern per entry.** If a body accumulates a second independent piece
  of work, split it.
- **Cross-reference, don't duplicate.** Related entries link by title
  ("see 'Entry title'"). Automation candidates additionally live in
  `docs/AUTOMATIONS.md`; an entry may point there rather than restating.
- When an entry's premise goes stale (e.g. "production has no worker" after a
  worker ships), fix the body in place — a tracker that lies is worse than
  none.

## Attribution when appending

Agents appending on the owner's behalf write `**Author:** Geoffrey` only when
the item is the owner's explicit note/request/decision from the conversation.
Everything else — including items the owner's question merely inspired — is
`**Author:** Claude`.
