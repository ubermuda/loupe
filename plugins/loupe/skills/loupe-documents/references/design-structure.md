# Design structure

Rule 15 of `../SKILL.md`. Read this before you write a product design or a tech design.

## At a glance

Make "At a glance" the first `##` section. A reviewer reads it to learn the change and what they must decide.

1. State the change in two or three sentences.
2. Add a diagram when it helps a design with moving parts.
3. When the design has open decisions, list them as a numbered list. Link each one to its section.

Loupe gives each heading an id. The id is `heading-`, then the heading text in lowercase, with each run of other characters changed to one hyphen. `## D1: Who may delete a tag` gets the id `heading-d1-who-may-delete-a-tag`.

```markdown
## At a glance

A project owner can add tags to cards, and filter the board by a tag. Tags belong to one project.

Open decisions:

1. [D1: Who may delete a tag](#heading-d1-who-may-delete-a-tag)
2. [D2: What a deleted tag does to its cards](#heading-d2-what-a-deleted-tag-does-to-its-cards)
```

## Decisions

Give each open decision its own section, with a stable ID in the heading.

1. Write the "**Decision needed:**" paragraph (rule 5). Name your recommendation and your confidence: high, moderate or low. Give the strongest argument against it.
2. When the decision has two or more real options, add a table with the columns Option, Pros and Cons. Write one row for each option.
3. Add a worked example under the table. Take one real case from the project, such as a card, a rule or a page. Write one line that states the case, then a numbered list. Start the list with "Today" when the decision changes existing behaviour. Then add one entry for each option, in the order of the table rows. Use the same case in each entry.
4. Put the decision fence under the example. Use the same options in the same order as the table rows.

Keep the reasons in the table. The fence holds only its question and the one-line options (`decision-fences.md`). End the recommended option with its confidence marker, such as `(recommended: moderate)`, so Loupe shows a badge on it.

Each entry of the example shows the input and what the user or the system sees. Prefer a short code block, a before and after, or a list of steps to prose. Keep each entry near ten lines. Put the example above the fence, because a fence takes only one question paragraph. When a revision answers the decision, keep the example in its section above the `**Decided:**` line.

A Decisions log entry of a product document stays one line with its reason, and gets no example. A Decided entry of a tech design follows `../../loupe-stage-tech-design/SKILL.md`, and gets no example.

```markdown
## D1: Who may delete a tag

**Decision needed:** who may delete a tag. I recommend option 1, with moderate confidence. The strongest argument against it: a member can remove a tag that other members use.

| Option | Pros | Cons |
|---|---|---|
| 1. Any project member | Matches who may create a tag | A member can remove a tag in use |
| 2. The project owner only | No surprise removals | The owner must do every clean-up |

**Example:** a project has the tag `urgent` on 12 cards. A member deletes it.

1. Any project member: the member deletes `urgent`. The 12 cards lose it, and the owner sees the change on the board.
2. The project owner only: the member sees no delete button. The member asks the owner, who deletes the tag.

<!-- decision: tag-delete-role -->

Who may delete a tag?

1. Any project member (recommended: moderate)
2. The project owner only

<!-- /decision -->
```

## Diagrams

A diagram is encouraged and never required, in any section. Choose the Mermaid type from the subject.

| Subject | Mermaid type |
|---|---|
| Steps and branches | Flowchart |
| Who calls whom | Sequence diagram |
| The states of one thing | State diagram |
| Entities and their links | ER diagram or class diagram |

1. Write each diagram in Mermaid, in a fenced `mermaid` block.
2. Do not turn a list into a diagram.
3. Let the diagram replace the prose it covers.
4. Keep each diagram small enough to read on one screen.

The Loupe review page draws the diagram only when the operator turns on the diagrams flag. Otherwise it shows the Mermaid source.

## Structure replaces prose

1. A table or a diagram replaces the text it covers. Do not repeat its content in prose.
2. Leave out a section with nothing real to say.
3. A Light document has an At a glance of two sentences. It skips the Priorities and How others do it sections. A Light tech design keeps a short Architecture section.
