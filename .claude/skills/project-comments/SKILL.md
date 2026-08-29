---
name: project-comments
description: "Use when writing, reviewing, or editing code comments and docblocks anywhere in this repository, including when you are about to explain a tricky fix, justify a design choice, or document why an approach was rejected."
---

# Code comments

## Principle

A comment earns its place only if it records something a competent reader cannot
recover from the code, its tests, or `git log`. You pay for narration on every
future read.

The default is no comment. The code says what it does. The test says what it
guarantees. The commit message says why it changed. A comment covers the fourth
thing: a constraint that is invisible at the call site and expensive to
rediscover.

## The budget

| Length | Bar it must clear |
|---|---|
| 0 lines | Default. |
| 1–2 lines | A non-obvious constraint, stated flatly. |
| 3–5 lines | Two interacting constraints, or a rejected alternative that a reader would otherwise retry. |
| 6+ lines | Almost always wrong. Move it to the commit message or the PR body. |

At a sixth line, stop. Ask what the reader must know **at this line of code** to
avoid breaking something. Write only that.

## Keep

Keep a comment that records an invisible constraint:
- Ordering or coupling that the type system cannot express
  (*"must run first; it calls `EntityManager::clear()` as it iterates"*)
- Concurrency and transaction boundaries
- Why you did not use the obvious approach, when a reader would otherwise try
  it and revert your work
- External contracts you do not control, such as an API's undocumented behaviour

## Cut

Cut what the reader recovers from elsewhere:
- A restatement of what the next line does
- A tutorial on framework behaviour; link the concept instead
- The investigation behind the fix, which belongs in the commit message
- Benchmark numbers and measurements, which belong in the PR body
- Design-decision logs and alternatives considered, which belong in the PR body
- Follow-up work, which belongs in `docs/NEXT_STEPS.md` and never in a `TODO`
  comment

## Example

Ten lines shipped on a branch in this repo:

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

Most of it is a decision log, and it belongs in the PR. One fact survives: what
stops the next person from "improving" this into a partial index.

```php
// Partial indexes don't round-trip through DBAL's comparator (Postgres
// rewrites the predicate), so migrate-diff never settles. Keep these plain.
```

Two lines. The reader learns the trap, and the reasoning lives in the PR.

## Also enforced mechanically

Three checks are hard failures. This skill is the judgment layer above all
three: passing every check does not make a 17-line comment worth keeping.

- `NoTodosCheck` (`just gamache`) fails on `TODO`, `FIXME` and `XXX`.
  Follow-ups go in `docs/NEXT_STEPS.md`.
- `SelfContainedCommentsCheck` fails on references to tasks, phases, spec
  sections, handoff docs or dated decisions. State the underlying fact instead.
- `CommentBudgetCheck` fails on any run of **6 or more** consecutive comment
  lines, in PHP, Twig, JS, CSS, YAML, the justfile and `.env` alike.

The budget check fails instead of warning, because a check that cannot fail is a
check whose green result carries no information.

`@comment-budget-ignore` supplies the judgment the line count lacks. Mark one
line of a block that earns its length, such as a file header for a distributed
artefact, and the run stays green. The mark is a decision on the record. An
unmarked block says it was not worth one.

## Red flags

You are writing narration if the comment:
- Could start with "This is because I..." or "Note that we tried..."
- Explains the diff rather than the code
- Would be stale the moment someone refactors the lines below it
- Repeats a name that is already in the signature
- Is longer than the function it describes

Cut every one of these, or move it to the commit message.
