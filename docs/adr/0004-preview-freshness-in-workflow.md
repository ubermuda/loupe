---
title: "0004: Let the workflow keep a preview current"
description: "The workflow template asks for preview work each time the code that a preview serves changes. The bridge rules do the refresh."
---

## Status

Proposed on 2026-10-09.

## Context

An epic preview serves the code of an epic branch, so the owner can review every merged child in one place. In this repository it is the worktree `.worktrees/epic-<n>`, detached at `origin/epic/<n>`.

The workflow asks for preview work at one moment only. The `epic-preview` rule of `config/workflows/lifecycle.yaml` fires on a child card, when its pull request merges into the epic branch. Then the "Refresh the epic preview" section of `loupe-stage-merge` moves the preview to the new head.

Other events also move the epic branch, and none of them asks for preview work:

- A fix round on the epic pull request pushes to `epic/<n>` directly.
- A sync merges `main` into `epic/<n>`.
- A person pushes to `epic/<n>` by hand.

Epic 390 is the example that raised this record. On 2026-10-09 a fix round pushed `5ec076d37`, which aligns the state mark to the right of the board tile. The preview stayed at `ee7c649e1`. The owner reviewed the old code twice. The next fix round spent a run to say that the head already had the fix, and changed nothing. Epic 611 had a stale preview on the same day.

ADR 0002 predicted this case. Its last paragraph says:

> Watch for a step inside a run that the environment needs, such as a refresh after the skill merges the base branch. That step has no rule yet. Keep it out of the skill, and give it a home in the rules when the need shows.

## Decision

Keeping a preview current is a workflow concern. The workflow template decides when a preview is due, and the bridge rules decide how to refresh it.

The rule in detail:

- A workflow rule asks for preview work each time the head of the branch that a preview serves changes. The cause of the change does not matter: a child merge, a fix round, a sync or a hand push.
- The rule lives in the workflow template, so a project can read it and change it. The app code does not hardcode it.
- A bridge rule does the refresh. A refresh needs no judgment, so it is an `action: command` rule with no agent, like `teardown`. This follows ADR 0001 and ADR 0002.
- No stage skill refreshes a preview. The fix round and the merge stage push code, and the workflow sees the new head.

The workflow must learn which head a preview serves. The app cannot read the bridge machine, so it cannot read the preview itself. That needs a new condition in the workflow engine, and its shape is still open. `card.site_review.check_stale` is the nearest example, because it compares a stored value with the head commit of the pull request. One shape stores the head that the last preview work refreshed to, and fires when the head of the pull request differs.

## Rejected options

- The fix round refreshes the preview after it pushes: this is a small skill edit. But it puts an environment step in a stage skill, which ADR 0002 forbids. It also misses a sync and a hand push.
- The app refreshes, or asks for a refresh, on each push to an epic branch: the trigger is exact. But it hides a workflow decision in app code, where a project cannot read it or change it.
- Keep the trigger on the child merge only: nothing changes. The preview goes stale after each fix round on an epic, and the owner reviews old code.

## Consequences

Better:

- A preview follows its branch, whatever moved the branch.
- The owner does not review old code, and a fix round does not spend a run to report a stale preview.
- The refresh runs as a fixed command, with no tokens and no worker slot.
- The trigger is in the template, next to the other rules, where a person can find it.

Worse:

- The engine needs a new condition, with its own state and tests.
- The `epic-preview` rule moves from the child card to the epic card. The "Refresh the epic preview" section of `loupe-stage-merge` loses its refresh step, and ADR 0003 asks for that removal in the same piece of work. The section also writes the preview links of each child into the epic pull request body. That step proves each link on the preview, so it must run after a refresh that reached the merge commit of the child. Otherwise a new page reads "not proved", and no later refresh proves it again.
- A refresh on every push runs `just worktree-up` more often. Each run takes about a minute on the owner's machine.

Watch for a second kind of preview that the workflow does not keep current. A card preview stays current today, because the fix round pushes from inside the card worktree. Apply this record if that changes.
