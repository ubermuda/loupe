---
name: project-tech-design
description: Use when you write or revise a technical design for this project — a document that settles an architecture, an entity model, a module boundary, or a subsystem, before any implementation plan exists. Also use when a review comment reopens such a decision.
---

# Technical designs

A technical design settles an architecture. An implementation plan settles tasks. Write the design first.

Send every design to the Loupe app for review. **Invoke `loupe-documents` before you write.** That skill gives the format rules for the review UI. This skill gives the content rules.

Follow `plugins/loupe/skills/loupe-stage-tech-design/SKILL.md` for the design sections, the recommendations and the estimate marks. This skill adds the rules that belong to this project.

Write the design for a reader who knows the product, as `loupe-documents` `references/design-structure.md` "Write for a reader who knows the product" says.

Write the document in ASD-STE100 Simplified Technical English. The writing rules are in `compressing-skills`.

An ADR name such as `0001-app-over-worker` never gets a tooltip on the review page. Give it a meaning inline at its first mention in each section, as `loupe-documents` rule 16 says for an outside ID.

## Verify each claim against the code

Read the code before you state a fact about it. Never write a count from memory.

One audit design stated five wrong numbers. It claimed 11 security call sites, and the code had 14. It claimed 22 silent handlers, and the code had 27. It claimed one privacy violation, and the code had 26. It also described a renaming workstream that did not exist, because every operation name was already correct.

Run the search. Count the result. Put the number in the document.

Say what you could not verify. Silence reads as confidence.

## Separate the trigger from the problem

The trigger is the event that made you look. The problem is what the code gets wrong.

A lapsed trial blocked an agent. That was the trigger. One status column cannot hold two facts. That was the problem.

Write both, and keep them apart. A design that fixes the trigger leaves the problem in place.

## Make the change easy, then make the easy change

Kent Beck states the rule. Apply it when a small feature does not fit the model.

A comped account had to stack with a Stripe subscription. One status column could not express it. The correct design added subscription records first, and the comp then cost one row.

Difficulty is evidence. When a small change fights the model, the model is the work. Say so in the document, and plan the enabling change as its own step.

## Cover the project's own checks

Give the document a section for the checks the work must satisfy. Gamache, arkitect, and the migration rules all constrain a design.

List the rules that apply, and say what each one forces. A design that ignores them produces a branch that cannot pass `just ci`.

Read the five gamache layers before you claim no rule applies. `AGENTS.md` lists them.

## Order the work around live data

A step that touches live data needs its own entry in the work order. Say what breaks if the migration is wrong.

## Take the example case from this project

Take the case of each worked example from this project, such as a card of the board, a page of the dev seed or a rule of a skill.

## Common mistakes

| Mistake | Correction |
|---|---|
| A count taken from memory | Run the search, then write the number |
| The trigger described as the problem | Write both, and keep them apart |
| "All 107 call sites" | Verify the scope; 45 of them were diagnostics |
| A decision fence with two paragraphs above the options | One paragraph converts, two do not |
| An implementation plan submitted as a design | Settle the architecture first |
| A decision written in class names | Write it for a reader who knows the product |
| "I recommend option 2", or "under D1 option 1", in a decision | Say what the option does, and state the fact inline (`loupe-documents` rule 18) |
