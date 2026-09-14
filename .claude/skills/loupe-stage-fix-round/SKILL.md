---
name: loupe-stage-fix-round
description: "Use when a Loupe card needs one round of review feedback answered in its current stage, or when a prompt names loupe-stage-fix-round."
---

# Fix round

Answer one round of feedback on the current stage of one card, then stop.

## Procedure

1. Read `../loupe-stage-product-design/references/stage-contract.md`. Follow its rules for the whole run, and take its first steps. In the Implementation column, the contract rules of `loupe-stage-implementation` replace rule 1.
2. Read the card `status`, and take one branch:
   - `product-design`: the design round, for the product document.
   - `tech-design`: the design round, for the tech design.
   - `implementation`: the code round.
   - Any other column, such as `backlog` or `done`: stop with `STAGE RESULT: no fix round in <status>`.

### Design round

1. Find the linked document as the stage skill does. The product document has the tag `product`, or a title that starts `Product design`. The tech design has the tags `design` and `decisions`, or a title that starts `Tech design`.
2. When the card links no such document, stop with `STAGE RESULT: no linked <document>`. Never create one.
3. When its `status` is `approved`, stop with `STAGE RESULT: <document> already approved`.
4. Invoke `loupe-documents`. For a tech design, invoke `project-tech-design` too. For a product document, read `../loupe-stage-product-design/references/product-document.md`.
5. Follow `../loupe-stage-product-design/references/review-round.md`. The requirement source is the card body for the product document, and the product document for the tech design.
6. When the review round ends with `<document> unchanged`, stop with `STAGE RESULT: nothing to fix` instead.

### Code round

1. Read `references/pull-request-feedback.md`, then run `gh pr view` on each linked pull request. When none is `OPEN`, stop with `STAGE RESULT: no open pull request`.
2. Read the inline comments, the unresolved review threads, the reviews and the checks. Wait for pending checks as the implementation skill says. Read the failed logs of each failing check.
3. When no thread is unresolved, no review requests changes, and no check fails, stop with `STAGE RESULT: nothing to fix`. Change nothing.
4. Invoke `project-worktrees` and `working-with-prs`. Read `../loupe-stage-implementation/SKILL.md` and its `references/commands.md`.
5. When `.claude/worktrees/card-<number>` exists, call `EnterWorktree` with its absolute path. Otherwise set it up from the pull request branch first. Keep every existing commit.
6. When the binding fails, or the branch is not the pull request branch, stop with `STAGE RESULT: blocked: worktree binding failed`.
7. Fix the feedback. Follow the implementation skill for worktree safety, subagents, `just cs` and `just ci`, the push, the CI wait and the Codex review.
8. With green CI and a clean review, stop with `STAGE RESULT: fixed <pr url>`.
9. On a block, record it and stop as implementation step 14 says.
