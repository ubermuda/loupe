---
name: loupe-stage-product-design
description: "Use when a card enters the Product design column of a Loupe board, or when a prompt names loupe-stage-product-design."
---

# Product design stage

Write or revise the product document of one card, link it, and stop. Leave architecture, entity and module decisions to the tech design stage.

Change nothing but Loupe documents, and never move the card. `references/stage-contract.md` holds the full rules.

## Procedure

1. Read `references/stage-contract.md`. Follow its rules for the whole run.
2. Load the harness adapter and read the repository profile, as the contract says.
3. Connect to the Loupe tools, as the contract's first steps say.
4. Load the `loupe-board` instruction.
5. Call `card_get`, and run the contract's column check.
6. Load `loupe-documents`, then read `references/product-document.md`.
7. Find the product document among the linked documents, as the contract says. It has the tag `product`, or a title that starts `Product design`.
8. When step 7 finds none, search `document_list` for the title `Product design: <card title>`, as the contract says.

### Revise, when step 7 or 8 finds the document

1. When its `status` is `approved`, stop with `STAGE RESULT: product document already approved`.
2. Read code and docs only to state current behaviour.
3. Follow `references/review-round.md`. The document is `product document`, and the requirement source is the card body.

### Create, when neither step finds a document

1. Read code and docs only to state current behaviour.
2. Call `document_create` with the title `Product design: <card title>`. Use the tags `design` and `product`, or the spelling `tag_list` already has for them.
3. Link the new id to the card (contract rule 5). Stop with `STAGE RESULT: product document created <id>`.

Write the final reply as the contract says.
