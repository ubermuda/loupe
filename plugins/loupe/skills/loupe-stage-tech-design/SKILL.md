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
9. Find the tech design among the linked documents, as the contract says. It has the tags `design` and `decisions`, or a title that starts `Tech design`. Skip the tech design of the parent card, as `../loupe-stage-implementation/references/breakdown.md` "Build a child" says.
10. When step 9 finds none, search `document_list` for the title `Tech design: <card title>`, as the contract says. The document to reference is the product document, when there is one.

### Revise, when step 9 or 10 finds the design

1. When its `status` is `approved`, stop with `STAGE RESULT: tech design already approved`.
2. Read the code and those design inputs.
3. Judge the size again, as "Judge the size" says. A revision never changes the ID of an entry.
4. Follow `../loupe-stage-product-design/references/review-round.md`. The document is `tech design`, and the requirement source is the one step 7 chose.

### Create, when neither step finds a design

1. Read the code and those design inputs. Answer each entry that applies.
2. Write the sections that "The design sections" lists. Judge the size, as the next section says.
3. Call `document_create` with the title `Tech design: <card title>`. Set `references` to the product document id, or leave it empty when the requirement source is the card body. Use the tags `design` and `decisions`, or the spelling `tag_list` already has for them.
4. Link the new id to the card (contract rule 5). Stop with `STAGE RESULT: tech design created <id>`.

### Judge the size

1. Judge whether one pull request of normal size can build the design. A worker builds a small task with clear limits better than a large one.
2. When the design needs more than one such pull request, add a Breakdown section. Each entry becomes one child card. Write it in the format of `../loupe-stage-implementation/references/breakdown.md`.
3. Otherwise add no Breakdown section.
4. Never write a Breakdown section for a card that has a parent, because epics do not nest. A child with no entry line of `../loupe-stage-implementation/references/breakdown.md` gets a design of its own, like any other card.

Write the final reply, its reason code and the structured result as the contract "Final reply" section says.

## The design sections

Use these `##` sections, in this order. Follow `../loupe-documents/references/design-structure.md` for At a glance, the decisions and the tables. Put each section that the profile instructions add, such as the current state or the project checks, before Decided.

1. At a glance.
2. Priorities. Cite the `P` entries of the product document that the design serves. With no product document, take them from the card body.
3. Architecture. Name each part that the change adds or changes. Give each part one table row, with its role today and its change. Write "new" as the role of a part that the change adds. Then describe the main flow step by step. A diagram is optional, and `../loupe-documents/references/design-structure.md` "Diagrams" gives the types.
4. How others do it. Give two or three libraries or systems that solve the same problem. Link each one, and give one takeaway.
5. One section for each open decision, with a stable ID such as `D1` in its heading. Each decision cites the `R` and `P` IDs it serves.
6. Decided. Write each entry in two to four sentences. Give the reason, the option that lost and why it lost, and the cost that the choice accepts. For a reversal, name the answer that lost and the argument that changed it.
7. The work order. List the steps with stable IDs. Say which open decision blocks which step. Write a Breakdown section instead when "Judge the size" asks for one.

Write a Light design when the product document has no Priorities section, or when the card body asks for a small change. Its At a glance is two sentences, and it skips Priorities and How others do it. It keeps a short Architecture section right after At a glance, with only the parts that change and the main flow.

## Code sketches

Show a short snippet where it makes a part or a decision concrete. Put the snippet in the section that it supports. The snippet can be an interface, a signature, a config block, a payload, or the columns of a migration.

1. Show the shape only. Write no method body and no test.
2. Keep the snippet near 20 lines.
3. Name the file that the snippet lands in, and mark the snippet as a sketch.

## Facts and recommendations

1. Read the code before you state a fact about it. Run the search, and write the count it gives, never a count from memory.
2. Mark each entry, and each cost, as checked or estimated. An estimate beside checked entries reads as checked. Say what you could not verify.
3. Give each recommendation a confidence: high, moderate or low. Give the strongest argument against it.
4. Never inflate the cost of the option you reject. An overstated argument hides how close the call was.
5. Name the cost that each decision accepts, in the entry that causes it.
6. The owner sets the quality bar. Inform the decision, and do not make it.
