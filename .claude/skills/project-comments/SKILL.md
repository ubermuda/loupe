---
name: project-comments
description: Use when writing, reviewing, or editing code comments and docblocks anywhere in this repository — including when you are about to explain a tricky fix, justify a design choice, or document why an approach was rejected.
---

# Code comments

## Overview

A comment earns its place only if it records something a competent reader
**cannot recover** from the code, its tests, or `git log`. Everything else is
narration, and narration is a cost paid on every future read.

**The default is no comment.** The code says what it does. The test says what it
guarantees. The commit message says why it changed. A comment is for the fourth
thing: a constraint that is invisible at the call site and expensive to
rediscover.

## The budget

| Length | Bar it must clear |
|---|---|
| 0 lines | Default. |
| 1–2 lines | A non-obvious constraint, stated flatly. |
| 3–5 lines | Two interacting constraints, or a rejected alternative that a reader would otherwise retry. |
| 6+ lines | Almost always wrong. Move it to the commit message or the PR body. |

If you are writing a sixth line, stop and ask what the reader must know **at
this line of code** to avoid breaking something. Write only that.

## Keep vs cut

**Keep** — invisible constraints:
- Ordering/coupling that the type system cannot express
  (*"must run first; it calls `EntityManager::clear()` as it iterates"*)
- Concurrency and transaction boundaries
- Why the obvious approach was **not** used, when a reader would otherwise
  try it and revert your work
- External contracts you do not control (an API's undocumented behaviour)

**Cut** — recoverable from elsewhere:
- Restating what the next line does
- Tutorials on framework behaviour — link the concept, don't teach it
- The investigation you ran to reach the fix → commit message
- Benchmark numbers and measurements → PR body
- Design-decision logs and alternatives considered → PR body
- Follow-up work → `docs/NEXT_STEPS.md` (never a `TODO` comment)

## Example

Real, from this repo. Ten lines shipped on a branch:

```php
// The hourly sweep runs three candidate queries (BillingProfileRepository),
// none of which shared indexed columns before this: findExpiredTrials() and
// findTrialEndedSubscribers() both filter on (status, trial_ends_at);
// findCanceledPastPeriod() filters on (status, current_period_end) instead —
// a distinct pair, so it gets its own composite index. Partial indexes
// (`WHERE survey_sent_at IS NULL` / `WHERE status = 'canceled'`) would be
// tighter, but Postgres rewrites the predicate on storage (adding casts and
// parens) and DBAL's schema comparator does not normalize that back to the
// declared form, so `migrate-diff` never reaches "no changes" — a plain
// composite index is the one that round-trips cleanly.
```

Most of that is a decision log, and it belongs in the PR. What survives is the
one fact that stops the next person "improving" this into a partial index:

```php
// Partial indexes don't round-trip through DBAL's comparator (Postgres
// rewrites the predicate), so migrate-diff never settles. Keep these plain.
```

Two lines. The reader learns the trap; the reasoning lives in the PR that
introduced it.

## Also enforced mechanically

- **No `TODO` / `FIXME` / `XXX`** — `NoTodosCheck` (`just gamache`) fails on
  them. Follow-ups go in `docs/NEXT_STEPS.md`.
- **Comments must be self-contained** — `SelfContainedCommentsCheck` fails on
  references to tasks, phases, spec sections, handoff docs or dated decisions.
  State the underlying fact instead.

Both are hard failures. The budget above has a third check behind it,
`CommentBudgetCheck`, which reports any run of **6 or more** consecutive comment
lines — in PHP, Twig, JS, CSS, YAML, the justfile and `.env` alike.

**It is advisory: it warns and `just gamache` still exits 0.** A line count
cannot tell a good six-line comment from a bad one, so it does not get to fail
your build. Read its output anyway — it is the only thing measuring the drift
this skill exists to prevent, and a comment it flags is over budget until you
have decided otherwise. When a long block genuinely earns its length (a file
header documenting a distributed artefact, say), suppress it deliberately with
`@comment-budget-ignore` on one of its lines rather than leaving it to be
re-flagged forever.

This skill remains the judgment layer above all three: passing every check does
not make a 17-line comment worth keeping.

## Red flags

You are writing narration if the comment:
- Could start with "This is because I..." or "Note that we tried..."
- Explains the diff rather than the code
- Would be stale the moment someone refactors the lines below it
- Repeats a name that is already in the signature
- Is longer than the function it describes

**All of these mean: cut it, or move it to the commit message.**
