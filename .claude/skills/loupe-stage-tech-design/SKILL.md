---
name: loupe-stage-tech-design
description: "Use when a card enters the Tech design column of a Loupe board, or when a prompt names loupe-stage-tech-design."
---

# Tech design stage

Write or revise the tech design of one card from its approved product document, link it, and stop.

Change nothing but Loupe documents, and never move the card. `../loupe-stage-product-design/references/stage-contract.md` holds the full rules.

## Procedure

1. Read `../loupe-stage-product-design/references/stage-contract.md`. Follow its rules for the whole run.
2. Find the Loupe tools, as the contract's first steps say.
3. Invoke `loupe-board`.
4. Call `card_get`, and run the contract's column check.
5. Read the tags of each linked document with `document_get`. The product document has the tag `product`, or a title that starts `Product design`. When no product document has `status` `approved`, stop with `STAGE RESULT: no approved product document`.
6. Read the product document with `document_get`. Every decision cites the `R` IDs it serves.
7. Invoke `loupe-documents`, then `project-tech-design`.
8. The whole CLAUDE.md section "What a new entity or feature must also register" is a required input. It includes the table and the list "Four more that no registry covers".
9. Find the tech design among the linked documents, as the contract says. It has the tags `design` and `decisions`, or a title that starts `Tech design`.
10. When step 9 finds none, search `document_list` for the title `Tech design: <card title>`, as the contract says. The document to reference is the product document.

### Revise, when step 9 or 10 finds the design

1. When its `status` is `approved`, stop with `STAGE RESULT: tech design already approved`.
2. Read the code and that CLAUDE.md section.
3. Follow `../loupe-stage-product-design/references/review-round.md`. The document is `tech design`, and the requirement source is the product document.

### Create, when neither step finds a design

1. Read the code and that CLAUDE.md section. Answer each entry that applies.
2. Call `document_create` with the title `Tech design: <card title>` and `references` set to the product document id. Use the tags `design` and `decisions`, or the spelling `tag_list` already has for them.
3. Link the new id to the card (contract rule 5). Stop with `STAGE RESULT: tech design created <id>`.

Write the final reply as the contract says.
