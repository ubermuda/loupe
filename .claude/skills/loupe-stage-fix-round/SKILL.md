---
name: loupe-stage-fix-round
description: "Use when a Loupe card needs one round of review feedback answered in its current stage, or when a prompt names loupe-stage-fix-round."
---

# Fix round

Answer one round of feedback on the current stage of a card.

## Procedure

1. Read `../loupe-stage-product-design/references/stage-contract.md`. Load the Loupe tools and `EnterWorktree` in one ToolSearch, as its first steps say.
2. Invoke `loupe-board`.
3. Call `card_get`, and run the contract's column check.
4. Read the card `status`, and take one branch:
   - `product-design` or `tech-design`: the design round for the product document or the tech design, under the stage contract rules.
   - `implementation`: the code round, under the contract of `../loupe-stage-implementation/SKILL.md` instead.
   - Any other column: stop with `STAGE RESULT: no fix round for column <status>; expected product-design, tech-design or implementation`.

### Design round

1. Find the document among the linked documents. The product document has the tag `product`, or a title that starts `Product design`. The tech design has the tags `design` and `decisions`, or a title that starts `Tech design`.
2. When the card links no such document, stop with `STAGE RESULT: no linked <document>`. Skip the contract's `document_list` search, and never create a document.
3. When its `status` is `approved`, stop with `STAGE RESULT: <document> already approved`.
4. When the `verdict` is `changes-requested`, check the triggers of `review-round.md` first: an open comment, an answered decision without a matching `**Decided:**` line, or an uncovered requirement. Stop with `STAGE RESULT: blocked: changes requested with no comment` only when none holds.
5. Invoke `loupe-documents`. For a product document, read `../loupe-stage-product-design/references/product-document.md`. For a tech design, invoke `project-tech-design`, and read the CLAUDE.md section "What a new entity or feature must also register".
6. Follow `../loupe-stage-product-design/references/review-round.md`. The requirement source is the card body for the product document, and the product document for the tech design.
7. When the review round ends `<document> unchanged`, stop with `STAGE RESULT: nothing to fix`.

### Code round

1. Read `references/pull-request-feedback.md`, then read each linked pull request with the forge adapter. When none is open, stop with `STAGE RESULT: no open pull request`.
2. Read the checks and the open feedback items, per the reference.
3. When no check fails and no item is open, stop with `STAGE RESULT: nothing to fix`.
4. Invoke `project-worktrees` and `working-with-prs`. Read `../loupe-stage-implementation/references/commands.md`.
5. Set up or refresh `.claude/worktrees/card-<number>` from the pull request branch, per the reference. Keep every existing commit.
6. When the binding fails or the branch differs from the pull request branch, stop with `STAGE RESULT: blocked: worktree binding failed`.
7. Fix every open item and failing check with the Edit and Write tools. Follow the implementation skill for subagents, borrowed skills, the gate, the push, the CI wait and Codex.
8. Post a marker reply for each handled item, per the reference.
9. When CI is green and two Codex passes in a row are clean, stop with `STAGE RESULT: fixed <pr url>`.
10. On a block, record it and stop per implementation step 15.
