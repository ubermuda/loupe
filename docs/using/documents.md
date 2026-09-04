---
title: "Documents and review"
description: "Submitting Markdown, reviewing it inline, and revising across versions."
---

A document belongs to a project. It carries Markdown, a title, an optional
description, tags, an optional place in a series, and links to other documents;
each submission mints a new **version**, and the review UI keeps every one of
them.

## Reviewing

`/projects/{projectId}/documents/{documentId}/review` renders the current
version. A reviewer selects a passage, comments on it, and the comment is
anchored to the text it quotes. A comment may carry a **replacement** — what the
reviewer wants that passage to become — which the author applies by rewriting
the Markdown and submitting a new version. Loupe never edits the document
itself.

Threads carry a status: pending, addressed, or resolved. The verdict on a
version is either an approval or a request for changes, submitted at
`/review/submit`.

Two views help across versions:

- `/review/versions/{versionNumber}` — any earlier version as it read then.
- `/review/diff/{from}/{to}` — what changed between two versions.

### Commenting on a diff

A diff accepts comments when its newer side is the current version. The comment
is an ordinary comment on that version, so it reads and re-anchors like every
other one.

Text the revision removed cannot be commented on, because the current version no
longer holds it, and the page says so when you select it. A diff that ends at an
older version stays read-only, because a comment made there would anchor to a
version nothing reads back.

## Revising

Submitting a new version **re-anchors** open comments onto it. A comment whose
quoted text still appears carries forward; one whose text is gone comes back
**orphaned**, because its anchor no longer exists. Rewriting the exact passage a
reviewer asked about therefore orphans that comment — normal, and not a reason
to avoid revising.

Re-anchoring copies a comment onto the new version rather than moving it, so
**comment ids do not survive a revision**. Reply to comments and mark them
addressed *before* submitting the new version, or re-read the review afterwards
and use the fresh ids. Both operations reject a stale id rather than silently
writing into a row nobody reads.

## Decision blocks

A document can ask the reviewer a question they answer by clicking rather than
by typing. Wrapping a list of options in a pair of HTML comments carrying an
identifier renders it as a group of radio buttons, and the answer comes back
with the review:

```markdown
<!-- decision: reset-link-host -->

Which host should an emailed reset link be built from?

1. Drop `x-forwarded-host` from `trusted_headers`
2. Generate emailed links from a pinned `default_uri`

<!-- /decision -->
```

The status line under the document title confirms each click. It names the option
you chose and the version it is recorded against.

The identifier is permanent. The answer is stored against the id rather than
against the words, so options can be reworded freely in a later version —
but **changing the id discards the answer**, with no error and no warning.
Treat it like a database column name.

A block can also take more than one answer. Mark every option `- [ ]` and Loupe
renders checkboxes, so the reviewer ticks any number of them:

```markdown
<!-- decision: ship-with -->

Which of these ship in the first release?

- [ ] The importer
- [ ] The exporter
- [ ] The admin page

<!-- /decision -->
```

Mark every option `- ( )`, or mark none of them, and the block takes exactly one
answer. Loupe strips the marker from the rendered document. A list that mixes
the two markers, or marks only some of its options, degrades to a plain list.

A multi-choice block reports its answers in `selections`, and reports null in
`selected`. Every fence written before checkboxes existed keeps its answer.

The comments are invisible in every other Markdown renderer, so a document read
outside Loupe still shows a plain list.

## Annotations

An HTML comment that is not part of a decision block renders as a visible note.
A comment on its own line becomes a block note. A comment inside a paragraph
becomes an inline note.

```markdown
<!-- note: this section still needs the migration numbers -->
```

**Do not wrap the comment in an HTML element that opens its own block.** Loupe
reads that whole region as one block of raw HTML. It keeps the wrapper and
drops the comment. The note then disappears, and nothing warns you:

```markdown
<div>
<!-- this note never appears -->
</div>
```

Blockquotes and list items are Markdown rather than raw HTML, so a comment
inside one renders as a note. The comments stay invisible in every other
Markdown renderer.

## Series

Tags say that documents belong together. A **series** also says in what order
you read them. A series has a name and belongs to one project. A document
belongs to at most one series, and holds a position in it, counting from 1.

Set the name and the position together. A position with no series numbers
nothing, and a series with no position cannot be read in order, so Loupe rejects
either one on its own. Two documents in one series may not hold the same
position. Two different series may both use position 1.

An agent sets the placement when it submits the document, with the `series` and
`seriesOrdinal` parameters of `document_create`. It can also move a document
later with `document_set_series`, or take it out of its series. Loupe stores the
name as its author spells it, and creates the series the first time a document
names it. Two spellings that differ only in case or spacing are one series, and
the first spelling is the one every reader sees. Use `series_rename` to change
it.

The documents list gets a series filter beside the tag filter. Pick a series and
the list shows only its documents, in their own order rather than newest first.
A document page shows the series and the position under the title.

Renaming a series keeps every document in place. A name another series already
holds is refused rather than merged, because two series carry two independent
numberings.

## Highlights

Highlights tint the passages a reviewer should read first. They carry no body
and cannot be replied to — they steer attention, nothing more. They belong to
the version current when they are set, and a new version drops them.

## The search language

Search stems words, so it must know the language a document is written in. Every
document carries its own.

A project holds the default. You choose it when you create the project, in the
**Document language** field on the new-project form and on the first step of the
first-run wizard. A document that names no language of its own takes that
default. An agent names another language per document through `document_create`.

The project settings screen carries the same field, so you can change the
default later. The change applies only to documents written after it. A document
fixes its own language when it is written. Nothing changes that language
afterwards, because a change needs a reindex that no screen or tool does today.

The default is `english` for every project that existed before this field, which
keeps those projects searching as they always did.

Pick **Other or mixed (no stemming)** for a project whose text has no single
language. Search then matches whole words only.

## Archiving

`/archive` and `/unarchive` take a document out of the default listing and put
it back. Nothing is deleted.
