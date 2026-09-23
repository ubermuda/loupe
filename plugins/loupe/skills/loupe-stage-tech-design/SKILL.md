---
name: loupe-stage-tech-design
description: "Use when a card enters the Tech design column of a Loupe board, or when a prompt names loupe-stage-tech-design."
---

# Tech design stage

Write or revise the tech design of one card, link it, and stop. The requirement source is the approved product document, or the card body when the card has no product document.

Change nothing but Loupe documents, and never move the card. `../loupe-stage-product-design/references/stage-contract.md` holds the full rules.

## Procedure

1. Read `../loupe-stage-product-design/references/stage-contract.md`. Follow its rules for the whole run.
2. Load the harness adapter and read the repository profile, as the contract says.
3. Connect to the Loupe tools, as the contract's first steps say.
4. Load the `loupe-board` instruction.
5. Call `card_get`, and run the contract's column check.
6. Read the tags of each linked document with `document_get`. The product document has the tag `product`, or a title that starts `Product design`.
7. Choose the requirement source:
   - A product document with `status` `approved`: read it with `document_get`. Every decision cites the `R` IDs it serves.
   - A product document that is not approved: stop with `STAGE RESULT: product document not approved`.
   - No product document: the owner skipped product design. The requirement source is the card body. Say so in the first section of the design, and cite the card body where a decision would cite an `R` ID.
8. Load `loupe-documents`. Load the tech design instructions and read the design inputs that the profile `Instruction files` section names. They are required inputs.
9. Find the tech design among the linked documents, as the contract says. It has the tags `design` and `decisions`, or a title that starts `Tech design`.
10. When step 9 finds none, search `document_list` for the title `Tech design: <card title>`, as the contract says. The document to reference is the product document, when there is one.

### Revise, when step 9 or 10 finds the design

1. When its `status` is `approved`, stop with `STAGE RESULT: tech design already approved`.
2. Read the code and those design inputs.
3. Follow `../loupe-stage-product-design/references/review-round.md`. The document is `tech design`, and the requirement source is the one step 7 chose.

### Create, when neither step finds a design

1. Read the code and those design inputs. Answer each entry that applies.
2. Call `document_create` with the title `Tech design: <card title>`. Set `references` to the product document id, or leave it empty when the requirement source is the card body. Use the tags `design` and `decisions`, or the spelling `tag_list` already has for them.
3. Link the new id to the card (contract rule 5). Stop with `STAGE RESULT: tech design created <id>`.

Write the final reply as the contract says.
