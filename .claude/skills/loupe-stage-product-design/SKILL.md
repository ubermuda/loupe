---
name: loupe-stage-product-design
description: "Use when a card enters the Product design column of a Loupe board, or when a prompt names loupe-stage-product-design."
---

# Product design stage

Write or revise the product document of one card, link it, and stop. Leave architecture, entity and module decisions to `project-tech-design`.

Change nothing but Loupe documents, and never move the card. `references/stage-contract.md` holds the full rules.

## Procedure

1. Read `references/stage-contract.md`. Follow its rules for the whole run.
2. Find the Loupe tools, as the contract's first steps say.
3. Invoke `loupe-board`.
4. Call `card_get`, and run the contract's column check.
5. Invoke `loupe-documents`, then read `references/product-document.md`.
6. Find the product document among the linked documents, as the contract says. It has the tag `product`, or a title that starts `Product design`.
7. When step 6 finds none, search `document_list` for the title `Product design: <card title>`, as the contract says.

### Revise, when step 6 or 7 finds the document

1. When its `status` is `approved`, stop with `STAGE RESULT: product document already approved`.
2. Read code and docs only to state current behaviour.
3. Follow `references/review-round.md`. The document is `product document`, and the requirement source is the card body.

### Create, when neither step finds a document

1. Read code and docs only to state current behaviour.
2. Call `document_create` with the title `Product design: <card title>`. Use the tags `design` and `product`, or the spelling `tag_list` already has for them.
3. Link the new id to the card (contract rule 5). Stop with `STAGE RESULT: product document created <id>`.

Write the final reply as the contract says.
