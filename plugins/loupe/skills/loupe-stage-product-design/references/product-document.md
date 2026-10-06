# The product document

Read this before you write or revise a product document. The `loupe-documents` rules apply to every section. Start the body with the first `##` heading, because Loupe shows the title itself (rule 1).

Use the sections below, in this order, as `##` headings. Write each section as a numbered list, so a reviewer can cite an entry (rule 2). Open each entry with a short lead sentence (rule 4). Follow `loupe-documents` `references/design-structure.md` for At a glance, the decisions and the tables (rule 15).

A Light session uses these sections: At a glance, Problem, Current behaviour, Proposed behaviour, Out of scope, Assumptions, Decisions log, For tech design, Scenarios, Open questions, and Docs and landing page impact. Its At a glance is two sentences, and it skips Priorities and How others do it. A Full session uses every section. Leave out a section with nothing real to say. Problem, Proposed behaviour, Decisions log, Scenarios, and Docs and landing page impact always stay.

## At a glance

State the change in two or three sentences. When the document has an "Open questions" section, list the open decisions, each linked to its heading there. Write the sentences as a paragraph, and number the list.

## Problem

State what goes wrong today, or what a user cannot do. Name the person who feels it. Leave the solution out of this section.

## Who it is for

Name each kind of user or operator the change serves. Use the roles the product already has, such as a project owner, a reviewer or an instance operator.

## Priorities

List each trade-off pair that the owner picked a side on. Give each entry a stable ID, `P1`, `P2` and so on, and lead the entry with it. Name the side that wins, as in "P1: An early first release over a complete permission model." The tech design cites these IDs in each decision. When no owner took part, mark each entry as a guess.

## How others do it

Give two or three products that solve the same problem. Link each one, and give one takeaway for this design. Look at the products and at how their users work. Leave libraries and systems to the tech design.

## Current behaviour

Describe what the product does today, from the user's side. Support each entry with the route or the doc page that shows it. Cite a file path only when neither exists. Read the code before you state a fact, and never write a count from memory. Say what you could not verify.

## Proposed behaviour

Describe what the user sees and does after the change. Give each entry a stable ID, `R1`, `R2` and so on, and lead the entry with it. Write these as `R` entries too: who may act, the error and empty states, and any flag or opt-in. Numbering restarts at every heading, so other sections cite `R3`, never "entry 3" (rule 3).

Write only behaviour a user or an operator can observe. Name no class, table, entity or module. The tech design makes those decisions.

## Out of scope

List what the change leaves out, including requests a reader can expect. Say for each entry whether it waits for later work or is refused.

## Risks

Answer the pre-mortem question: "This shipped and failed. Why?" Write each answer as one entry, and cite the `R` ID it threatens.

## Assumptions

List each guess that the author made and the owner did not check. The tech design stage can then challenge it.

## Decisions log

Record each question that the session asked, with its answer. When an agent wrote the document with no owner present, write one entry that says so.

## For tech design

List the technical points that the session parked, such as an entity, a table, an API or a module. The tech design stage decides them.

## Scenarios

Write each scenario as Given, When and Then, so a person can check it on a running instance. Cite the `R` IDs each scenario proves. Avoid words such as "fast", "easy" or "better" without a measure.

## Open questions

The writer decides the use of this section. The interactive session writes a fence only for a decision that the owner deferred. A session where the owner answered every question therefore has no "Open questions" section. The unattended stage runs with no owner, so it writes each open decision as a fence.

Put each open choice in its own decision fence (rule 12). Read `loupe-documents` `references/decision-fences.md` before you write one. Give each choice a `###` heading with a stable ID, such as `### D1: Export format`, so At a glance can link to it.

1. Put the "**Decision needed:**" lead-in, the context and your recommendation above the fence (rule 5). Give the recommendation a confidence: high, moderate or low.
2. When the choice has two or more real options, put the pros and cons table and the worked example of `design-structure.md` above the fence.
3. Give the fence one short question paragraph, then flat one-line options.
4. Choose a fence id from the subject, such as `export-format`. Never change an id after the document is published, because a changed id discards the answer.

When a revision folds an answer in, keep the fence and add a `**Decided:**` line under it (rule 6).

## Docs and landing page impact

Answer the documentation and landing page checks that the `Instruction files` section of the repository profile names.

1. Documentation. Say whether the change alters what a user or an operator does, sees or configures. Name the documentation page that covers it. When no page changes, say so and give the reason.
2. Landing page. Say whether the change adds, removes or alters a capability that the landing page of the product claims.
