---
title: "Documents and review"
description: "Submitting Markdown, reviewing it inline, and revising across versions."
---

A document belongs to a project. It carries Markdown, a title, an optional
description, tags, and links to other documents; each submission mints a new
**version**, and the review UI keeps every one of them.

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

The identifier is permanent. The answer is stored against the id rather than
against the words, so options can be reworded freely in a later version —
but **changing the id discards the answer**, with no error and no warning.
Treat it like a database column name.

The comments are invisible in every other Markdown renderer, so a document read
outside Loupe still shows a plain list.

## Highlights

Highlights tint the passages a reviewer should read first. They carry no body
and cannot be replied to — they steer attention, nothing more. They belong to
the version current when they are set, and a new version drops them.

## Archiving

`/archive` and `/unarchive` take a document out of the default listing and put
it back. Nothing is deleted.
