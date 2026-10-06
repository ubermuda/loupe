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

## Write for a reader who knows the product

The owner reads a design to make its decisions. The owner knows what the product does, and does not hold the code in mind.

1. Say what happens in product terms first: what a person sees, what the board does, what an agent does. Then name the code, when the reader needs it.
2. Write At a glance, each decision and each Decided entry for a reader with no file open. Put class names, fields, methods and file paths in Architecture, the work order and the project checks. A reader who knows the product can skip those parts.
3. Write the question and each option of a decision in plain words. Each option says what changes for a person or an agent. Two options can look the same to a person. Then name their difference in plain words, such as "keep a copy" or "count on each page load", and say what each one costs. In a tech design, put the code that each option changes in the "How each option works" list (see "Decisions").
4. A name that a person sees in the product is a plain word. Examples are a tool name, a column, a tag and a button label. A class, a field, a method or a file path is not a plain word. Keep it out of the question, the options, the table and the example.
5. Explain a new idea in one sentence where it first appears. Add a small example when it helps. Rule 16 of `../SKILL.md` covers an ID from another source. This item covers an idea, such as a kind of pause.
6. A fact with no mark is checked. Mark only an estimate, with "(estimated)". When a section states facts from the code, end it with one "Checked in the code" line that names the files behind them. Never tag each sentence with "(checked, File.php)".

Before, the decision is written in the terms of the code:

```markdown
**Decision needed:** what `TagDeleteHandler` does with `Card::$tags` when it removes a `Tag` row (checked, TagDeleteHandler.php). I recommend option 1, because `CardRepository::findByTag()` already returns them (checked, CardRepository.php).
```

After, the same decision is written in product terms:

```markdown
**Decision needed:** what happens to the cards of a tag that a member deletes. I recommend option 1: the cards lose the tag, and the board shows the change.

(The table, the example, the How list and the fence follow here.)

Checked in the code: `TagDeleteHandler.php`, `CardRepository.php`.
```

## Decisions

Give each open decision its own section, with a stable ID in the heading.

1. Write the "**Decision needed:**" paragraph (rule 5). Name your recommendation and your confidence: high, moderate or low. Give the strongest argument against it.
2. When the decision has two or more real options, add a table with the columns Option, Pros and Cons. Write one row for each option.
3. Add a worked example under the table. Take one real case from the project, such as a card, a rule or a page. Write one line that states the case, then a numbered list. Start the list with "Today" when the decision changes existing behaviour. Then add one entry for each option, in the order of the table rows. Use the same case in each entry.
4. In a tech design, add a "How each option works" numbered list under the example. A product document gets none, because the code belongs to the tech design. Write one entry for each option, in the order of the table rows. Each entry names the code that the option changes. This list is the one place in a decision where a class, a field or a file may appear.
5. Put the decision fence under the How list, or under the example when there is no How list. Use the same options in the same order as the table rows.

Keep the reasons in the table. The fence holds only its question and the one-line options (`decision-fences.md`). End the recommended option with its confidence marker, such as `(recommended: moderate)`, so Loupe shows a badge on it.

Each entry of the example shows the input and what the user or the system sees. Prefer a short code block, a before and after, or a list of steps to prose. Keep each entry near ten lines. The order is the table, the example, the How list of a tech design, then the fence. Put them above the fence, because a fence takes only one question paragraph. When a revision answers the decision, keep them in the section above the `**Decided:**` line.

A Decisions log entry of a product document stays one line with its reason. A Decided entry of a tech design follows `../../loupe-stage-tech-design/SKILL.md`. Neither gets an example or a How list. Write both in product terms.

The example below is a tech design decision. In a product document, leave out its How list. Keep the "Checked in the code" line only when the section states a fact from the code.

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

**How each option works:**

1. Any project member: `TagVoter` grants the `tag.delete` attribute to each member of the project.
2. The project owner only: `TagVoter` grants `tag.delete` to the project owner alone. The delete button checks the same attribute.

<!-- decision: tag-delete-role -->

Who may delete a tag?

1. Any project member (recommended: moderate)
2. The project owner only

<!-- /decision -->

Checked in the code: `TagVoter.php`.
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
