---
name: product-design
description: "Use when the owner wants to run an interactive product design session for a Loupe card, from a card or from a one-line idea, or when a prompt names /loupe:product-design."
---

# Interactive product design

Run a product design session with the owner, write the product document, link it to the card, and stop. The owner's approval of the document moves the card to Tech design.

This skill is not a stage skill. It loads no `stage-contract.md` and no harness adapter, and it asks the owner questions. Run it in the main session, because AskUserQuestion does not work in a subagent. You can send a subagent to find facts (Q1).

`references/session-flow.md` holds the levels L1 to L4 and the phases P0 to P10. `references/question-rules.md` holds the question rules Q1 to Q6 and the coverage checklist of P6.

## Procedure

1. Load the `loupe-board` and `loupe-documents` instructions.
2. Read the `Instruction files` section of `.loupe/lifecycle.md` for the writing style and the docs and landing page checks. When the file is missing, write plainly, and ask the owner about docs in P6.
3. Find the Product design slug in the `Board` section of that file. A line names the column that holds a card in product design. With no such line, use `product-design`.
4. Read `references/session-flow.md` and `references/question-rules.md`. Follow them for the whole session.
5. With a card, call `card_get`. Read the tags of each linked document with `document_get`, before any move. The product document has the tag `product`, or a title that starts `Product design`.
   - An approved product document: stop, and tell the owner.
   - An unapproved product document: it is the draft that P1 reads.
6. Run P0 to get the card into the Product design column, as `session-flow.md` says.
7. Run the phases of the level that P2 sets. Ask each question as `question-rules.md` says. Use AskUserQuestion when the answer has clear options, and plain chat when the tool is missing.
8. Read `../loupe-stage-product-design/references/product-document.md` before P10. It is the template, and it lists the sections a Light document keeps.
9. Run P10 only after the owner confirms the P9 readback. Write nothing before that.

## Where session output goes

Put each item in its section of the product document.

- A technical choice that Q6 parks goes in "For tech design".
- A guess that the owner did not check goes in "Assumptions" (A4).
- Each question and its answer go in "Decisions log" (A5).
- The answers of the P7 pre-mortem go in "Risks".
- The P8 scenarios go in "Scenarios".

## P10: write and link

1. With no draft, call `document_create` with the title `Product design: <card title>`.
2. Use the tags `design` and `product`, or the spelling `tag_list` already has for them. Without `product`, an approval moves nothing and shows no error.
3. With a draft, call `document_revise` on it instead. Keep each section with a standing approval unchanged, as `../loupe-stage-product-design/references/review-round.md` "An approved section wins" says.
4. Call `card_get` again. When the card does not link the document yet, call `card_update` with the existing `documentIds` plus the new id. The field replaces the whole set.
5. Never move the card after P0. Give the owner the review URL, and stop.
