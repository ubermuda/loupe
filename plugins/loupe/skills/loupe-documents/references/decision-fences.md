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

Do not repeat your recommendation inside the fence. The card is what the
reviewer answers, and the reasoning belongs above it, where rule 5 puts it. Keep
the "**Decision needed**" lead-in and your recommendation (rule 5) above the
fence, because the fence carries no question of its own, only the options.

Numbered and bulleted lists both convert, and rule 2 applies here as everywhere:
prefer numbers, so a reviewer can still write "option 2" in a comment alongside
clicking it. Keep the entries a flat list of one-line options: a nested list is
refused and renders as an ordinary list, and so is a second fence reusing an id
already used above it. Every other Markdown renderer hides the comments, so a
document read outside Loupe still shows the list, which is why the fence uses
comments rather than a visible marker.

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
