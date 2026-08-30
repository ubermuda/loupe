---
name: project-tech-design
description: Use when you write or revise a technical design for this project — a document that settles an architecture, an entity model, a module boundary, or a subsystem, before any implementation plan exists. Also use when a review comment reopens such a decision.
---

# Technical designs

A technical design settles an architecture. An implementation plan settles tasks. Write the design first.

Send every design to the Loupe app for review. **Invoke `loupe-documents` before you write.** That skill gives the format rules for the review UI. This skill gives the content rules.

Write the document in ASD-STE100 Simplified Technical English. The writing rules are in `compressing-skills`.

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

## Record every decision

Give the document a **Decided** section. List each settled choice with its reason.

A decision without its reason gets reopened. A decision with its reason gets built.

Mark a reversal explicitly. Name the answer that lost, and name the argument that changed it.

## State your confidence, and keep the alternative alive

Give a recommendation. Say how confident you are. Give the strongest argument against it.

The owner sets the quality bar. Your job is to inform that decision, not to make it.

Never inflate the cost of the option you rejected. An overstated argument hides how close the call was.

## Name the cost a decision accepts

Every decision buys something and pays something. Write both.

A snapshotted identifier is immutable. An unflushed actor then records null forever. Both sentences belong in the document.

A cost that appears first in a pull request body arrived too late.

## Cover the project's own checks

Give the document a section for the checks the work must satisfy. Gamache, arkitect, and the migration rules all constrain a design.

List the rules that apply, and say what each one forces. A design that ignores them produces a branch that cannot pass `just ci`.

Read the five gamache layers before you claim no rule applies. `CLAUDE.md` lists them.

## Order the work and name what blocks it

End with an ordered list of steps. Give each step a stable ID.

Say which open decision blocks which step. Say which steps run in parallel.

A step that touches live data needs its own entry. Say what breaks if the migration is wrong.

## Common mistakes

| Mistake | Correction |
|---|---|
| A count taken from memory | Run the search, then write the number |
| The trigger described as the problem | Write both, and keep them apart |
| A recommendation with no confidence | State high, moderate, or low |
| The rejected option dismissed in one clause | Give its strongest argument |
| An accepted regression left unwritten | Name it in the entry that causes it |
| "All 107 call sites" | Verify the scope; 45 of them were diagnostics |
| A decision fence with two paragraphs above the options | One paragraph converts, two do not |
| An implementation plan submitted as a design | Settle the architecture first |
