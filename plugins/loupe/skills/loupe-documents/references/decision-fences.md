# Decision fences

Rule 12 of `../SKILL.md`. Read this before you write a fence.

**A decision fence turns a choice into something the reviewer clicks.** Wrap the
alternatives in a pair of HTML comments carrying an identifier, and Loupe
renders them as a group of radio buttons whose answer comes back in
`document_get_review` under `decisions`:

```markdown
<!-- decision: reset-link-host -->

Which host should an emailed reset link be built from?

1. Drop `x-forwarded-host` from `trusted_headers`
2. Generate emailed links from a pinned `default_uri`

<!-- /decision -->
```

**A single paragraph before the options becomes the card's question**, and it is
the only prose the fence accepts. Write it as one short question that stands on
its own without the paragraph above the fence, because a reviewer reads that
line to know what they are being asked. It is optional, and a fence of only
options still converts, with no question on the card. Two paragraphs are one too
many: the block keeps all of its prose, degrades to the plain list it already
was, and mints no controls.

Each option line says what the option does, in plain words. Never write only a label such as "option B" or "the approach of D1". The decisions panel shows the question with no text around it (rule 18 of `../SKILL.md`).

Keep the "**Decision needed**" lead-in and your reasoning above the fence, where
rule 5 puts them. The fence holds only the question and the options.

**Mark the option you recommend at the end of its line.** Write
`(recommended: high)`, `(recommended: moderate)` or `(recommended: low)` after
the option text. Loupe removes the marker from the label and shows three stars
next to that option: three filled for high, two for moderate, one for low. The
words, such as "Recommended, moderate confidence", show on hover and on keyboard
focus. Mark one option only. A second marker
cancels both, so the block shows no badge and keeps the marker text. Other
Markdown renderers show the marker as ordinary text.

```markdown
1. Drop `x-forwarded-host` from `trusted_headers` (recommended: moderate)
2. Generate emailed links from a pinned `default_uri`
```

**A click saves at once.** The reviewer can also write a note, with a pick or
alone. The note saves shortly after the reviewer stops typing, and comes back as
`note` on the decision in `document_get_review`. A **Clear choice** button, beside the
"Pick one" chip, removes the pick and keeps the note. The last write wins: a save from an older version of the
document carries onto the current version by option label.

Numbered and bulleted lists both convert, and rule 2 applies here as everywhere:
prefer numbers, so a reviewer can still write "option 2" in a comment alongside
clicking it. Keep the entries a flat list of one-line options: a nested list is
refused and renders as an ordinary list, and so is a second fence reusing an id
already used above it. Every other Markdown renderer hides the comments, so a
document read outside Loupe still shows the list, which is why the fence uses
comments rather than a visible marker.

## Show an Option, Pros and Cons table as blocks

**Wrap every Option, Pros and Cons table in an options fence.** Put
`<!-- options -->` on its own line above the table and `<!-- /options -->` on
its own line below it. Loupe shows each row as a tinted block. The first column
is the name of the option, and the other columns sit side by side under their
own headings. On a phone the columns stack.

```markdown
<!-- options -->

| Option | Pros | Cons |
|---|---|---|
| 1. Any project member | Matches who may create a tag | A member can remove a tag in use |
| 2. The project owner only | No surprise removals | The owner must do every clean-up |

<!-- /options -->
```

1. The fence marks every table between its two comments. A fence may hold more
   than one table.
2. A table needs at least two columns. A table with one column stays a plain
   table.
3. A fence with no table inside, an opener with no closer, and a closer with no
   opener all do nothing. Loupe shows the stray comment as a visible note, as it
   does for any comment, so you can see the mistake.
4. A table outside a fence stays a plain table. Its text is the same in both
   shapes, so comments on it anchor the same way.
5. Other Markdown renderers hide the two comments and show a plain table.

## Ask for one answer, or for several

**A marker on the options says how many answers the block takes.** Mark every
option `- [ ]` and the reviewer can tick any number of them. Mark every option
`- ( )`, or mark none of them, and the block takes exactly one answer. Loupe
strips the marker, so it never shows in the rendered document.

```markdown
<!-- decision: ship-with -->

Which of these ship in the first release?

- [ ] The importer
- [ ] The exporter
- [ ] The admin page

<!-- /decision -->
```

Use `- [ ]` because it is the GFM task-list marker, so the block already reads
as a set of checkboxes on GitHub and in every other renderer. Do not tick an
option with `- [x]`: Loupe accepts the spelling and reports no answer for it,
because only a reviewer's click is an answer.

The markers must agree. A list mixing `- ( )` and `- [ ]`, or marking only some
of its options, is malformed, so it degrades to a plain list with no error.

A multi-choice block answers in `selections` rather than in `selected`. Read
`type` on the decision to know which kind you asked for, because `selected` is
null on every multi-choice block, answered or not.

**A fence written before this shipped keeps working.** Its stored answer is
still reported. An unmarked list is a single-choice block, which is what those
fences already are. A fence that used `- [ ]` before this shipped now asks for
several answers, so change it to `- ( )` if you meant one.

**An id is permanent once published.** Loupe stores the answer against the id,
not against the words, precisely so you can reword a decision block in the
revision that responds to feedback about it. **A changed id silently discards
the answer**: no error, no warning, and the decision reads as unanswered again.
Treat an id like a database column name, and rewrite the options freely but
never the id.

Ids are lowercase letters, digits and hyphens. They must **start** with a letter
or digit, and 64 characters is the exact ceiling: 65 is refused, and like every
other malformed fence it renders as a plain list with no error.
