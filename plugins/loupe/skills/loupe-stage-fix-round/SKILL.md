---
name: loupe-stage-fix-round
description: "Use when a Loupe card needs one round of review feedback answered in its current stage, or when a prompt names loupe-stage-fix-round."
---

# Fix round

Answer one round of feedback on the current stage of a card.

## Procedure

1. Read `../loupe-stage-product-design/references/stage-contract.md`. Load the harness adapter, read the repository profile, and connect to the Loupe tools, as it says.
2. Load the `loupe-board` instruction.
3. Call `card_get`, and run the contract's column check.
4. Read the card `status`, and take one branch:
   - `product-design` or `tech-design`: the design round for the product document or the tech design, under the stage contract rules.
   - `in-review` or `implementation`: the code round, under the contract of `../loupe-stage-implementation/SKILL.md` instead.
   - Any other column: stop with `STAGE RESULT: no fix round for column <status>; expected product-design, tech-design, implementation or in-review`.

### Design round

1. Skip the tech design of the parent card in this whole round, even when the prompt names its id, as `../loupe-stage-implementation/references/breakdown.md` "Build a child" says. When the prompt names a document id, the document is the linked document with that id. Otherwise find the document among the linked documents. The product document has the tag `product`, or a title that starts `Product design`. The tech design has the tags `design` and `decisions`, or a title that starts `Tech design`.
2. When the card links no such document, stop with `STAGE RESULT: no linked <document>`. Skip the contract's `document_list` search, and never create a document.
3. When its `status` is `approved`, stop with `STAGE RESULT: <document> already approved`.
4. When the `verdict` is `changes-requested`, check the triggers of `review-round.md` first: an open comment, an answered decision without a matching `**Decided:**` line, or an uncovered requirement. Stop with `STAGE RESULT: blocked: changes requested with no comment` only when none holds.
5. Load `loupe-documents`. For a product document, read `../loupe-stage-product-design/references/product-document.md`. For a tech design, load the instructions and read the design inputs that the profile `Instruction files` section names.
6. Follow `../loupe-stage-product-design/references/review-round.md`. The requirement source is the card body for the product document. For the tech design, it is the approved product document, or the card body when the card has no product document.
7. When the review round ends `<document> unchanged`, stop with `STAGE RESULT: nothing to fix`.

### Code round

The prompt line `Pull request <url> needs a fix: <reason>.` names what started the round. The reason is `checks-failed`, `conflict` or `changes-requested`. A run by hand can have no reason. Whatever the reason, fix everything that is open: a conflict with the base, each failing check and each open feedback item.

The round ends at the push. It never waits for CI, because the app reads the new head and sends the next fix request or the merge decision.

1. Read `references/pull-request-feedback.md`, then read each linked pull request with the forge adapter. When none is open, stop with `STAGE RESULT: no open pull request`.
2. Read the mergeability, then the checks and the open feedback items, per the reference. Read the checks once, and never wait for a pending one.
3. When the pull request is mergeable, no check fails and no item is open, stop with `STAGE RESULT: nothing to fix`. Otherwise, check for an epic pull request. Its head is the epic branch, as "Epic branches" in `../loupe-stage-merge/SKILL.md` says. When the profile `Epics` section says that branch takes every change through a pull request, the forge refuses a push. Then follow step 12 with the reason `epic branch takes changes only through a pull request <url>`.
4. Read `../loupe-stage-implementation/references/commands.md`, and load the profile `Instruction files`.
5. Set up or refresh the card worktree from the pull request branch, per the reference. Keep every existing commit.
6. When the binding fails or the branch differs from the pull request branch, stop with `STAGE RESULT: blocked: worktree binding failed`.
7. When the pull request conflicts, resolve it first, per "Resolve a conflict with the base" in the reference.
8. Fix every open item and failing check. Read the log of each failed check, and fix the cause. Follow the implementation skill for sub-agents, the gate and the code review. In this round, `<base>` is the base branch of the pull request, such as the epic branch of an epic child.
9. Push without force.
10. Post a marker reply for each handled item, per the reference. A conflict has no item, so it gets no reply.
11. Stop with `STAGE RESULT: waiting <pr url>`. Never move the card.
12. When a step cannot go on, record the block as "Record a block" in `../loupe-stage-implementation/references/commands.md` says. Post the refusal comment on the pull request, per "Post a refusal comment" in the same file. Stop with `STAGE RESULT: blocked: <reason>`.

Your final message starts with `STAGE RESULT:` as its very first characters. Write no sentence before it. After it, write at most three short sentences. End the first line with its reason code, and set the structured result, as `../loupe-stage-product-design/references/stage-contract.md` "Final reply" says.
