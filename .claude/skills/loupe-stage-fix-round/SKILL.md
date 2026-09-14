---
name: loupe-stage-fix-round
description: "Use when a Loupe card needs one round of review feedback answered in its current stage, or when a prompt names loupe-stage-fix-round."
---

# Fix round

Answer one round of feedback on the current stage of one card, then stop.

## Procedure

1. Read `../loupe-stage-product-design/references/stage-contract.md`, and take its first steps. Load `EnterWorktree` and `Monitor` in the same ToolSearch as the Loupe tools.
2. Read the card `status`, and take one branch:
   - `product-design` or `tech-design`: the design round for the product document or the tech design, under the stage contract rules.
   - `implementation`: the code round, under the contract of `../loupe-stage-implementation/SKILL.md` instead.
   - Any other column: stop with `STAGE RESULT: no fix round for column <status>; expected product-design, tech-design or implementation`.

### Design round

1. Find the document among the linked documents. The product document has the tag `product`, or a title that starts `Product design`. The tech design has the tags `design` and `decisions`, or a title that starts `Tech design`.
2. When the card links no such document, stop with `STAGE RESULT: no linked <document>`. Skip the contract's `document_list` search, and never create a document.
3. When its `status` is `approved`, stop with `STAGE RESULT: <document> already approved`.
4. Stop with `STAGE RESULT: blocked: changes requested with no comment` only when the `verdict` is `changes-requested`, no root comment is `pending`, and every answered decision has a `**Decided:**` line.
5. Invoke `loupe-documents`. For a product document, read `../loupe-stage-product-design/references/product-document.md`. For a tech design, invoke `project-tech-design`, and read the CLAUDE.md section "What a new entity or feature must also register".
6. Follow `../loupe-stage-product-design/references/review-round.md`. The requirement source is the card body for the product document, and the product document for the tech design.
7. When the review round ends with `<document> unchanged`, stop with `STAGE RESULT: nothing to fix`.

### Code round

1. Read `references/pull-request-feedback.md`, then `gh pr view` each linked pull request. When none is `OPEN`, stop with `STAGE RESULT: no open pull request`.
2. Read the checks against the pull request head, the review threads and the reviews, as the reference says.
3. When no thread needs action, no review requests changes, and no check fails, stop with `STAGE RESULT: nothing to fix`. Change nothing.
4. Invoke `project-worktrees` and `working-with-prs`. Read `../loupe-stage-implementation/references/commands.md`.
5. Set up or refresh `.claude/worktrees/card-<number>` from the pull request branch, as the reference says. Keep every existing commit.
6. When the binding fails, or the branch is not the pull request branch, stop with `STAGE RESULT: blocked: worktree binding failed`.
7. Fix the feedback with the Edit and Write tools. Follow the implementation skill for subagents, the borrowed skills, the gate, the push, the CI wait and the Codex review.
8. Reply to each thread you acted on, as the reference says. Never resolve a thread.
9. When CI is green and two Codex passes in a row come back clean, stop with `STAGE RESULT: fixed <pr url>`.
10. On a block, record it and stop as implementation step 14 says.
