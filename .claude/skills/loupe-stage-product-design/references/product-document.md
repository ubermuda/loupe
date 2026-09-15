# The product document

Read this before you write or revise a product document. The `loupe-documents` rules apply to every section. Start the body with the first `##` heading, because Loupe shows the title itself (rule 1).

Use the sections below, in this order, as `##` headings. Write each section as a numbered list, so a reviewer can cite an entry (rule 2). Open each entry with a short lead sentence (rule 4). Keep a section when it has no entries, and write one entry that says so.

## Problem

State what goes wrong today, or what a user cannot do. Name the person who feels it. Leave the solution out of this section.

## Who it is for

Name each kind of user or operator the change serves. Use the roles the product already has, such as a project owner, a reviewer or an instance operator.

## Current behaviour

Describe what the product does today, from the user's side. Support each entry with the route or the doc page that shows it. Cite a file path only when neither exists. Read the code before you state a fact, and never write a count from memory. Say what you could not verify.

## Proposed behaviour

Describe what the user sees and does after the change. Give each entry a stable ID, `R1`, `R2` and so on, and lead the entry with it. Write these as `R` entries too: who may act, the error and empty states, and any flag or opt-in. Numbering restarts at every heading, so other sections cite `R3`, never "entry 3" (rule 3).

Write only behaviour a user or an operator can observe. Name no class, table, entity or module. The tech design makes those decisions.

## Out of scope

List what the change leaves out, including requests a reader can expect. Say for each entry whether it waits for later work or is refused.

## Success criteria

Write each criterion as something a person can observe and check on a running instance. Cite the `R` ID each criterion proves. Avoid words such as "fast", "easy" or "better" without a measure.

## Open questions

Put each open choice in its own decision fence (rule 12). Read `loupe-documents` `references/decision-fences.md` before you write one.

1. Put the "**Decision needed:**" lead-in, the context and your recommendation above the fence (rule 5).
2. Give the fence one short question paragraph, then flat one-line options.
3. Choose a fence id from the subject, such as `export-format`. Never change an id after the document is published, because a changed id discards the answer.

When a revision folds an answer in, keep the fence and add a `**Decided:**` line under it (rule 6).

## Docs and landing page impact

Answer the documentation and landing page checks that the `Instruction files` section of the repository profile names.

1. Documentation. Say whether the change alters what a user or an operator does, sees or configures. Name the documentation page that covers it. When no page changes, say so and give the reason.
2. Landing page. Say whether the change adds, removes or alters a capability that the landing page of the product claims.
