---
name: loupe-documents
description: Use when writing or revising a document for the Loupe app — anything sent through the loupe MCP's create_document or revise_document (implementation plans, specs, RFCs, audit reports, retrospectives, any long-form review document).
---

# Writing documents for Loupe review

Documents submitted via `create_document` / `revise_document` are read in the
Loupe review UI, where reviewers select passages and comment on them. Format
for that reading context, not for a terminal or a README.

## Rules

1. **No H1 title in the body.** The document's title lives in Loupe's own
   `title` field; an `# Heading` repeating it in the markdown renders as a
   duplicate. Start the body directly with content (or the first `##`
   section).

2. **Prefer numbered lists over bullet lists.** Reviewers refer to entries in
   comments ("point 3 is wrong") — numbers make that possible, bullets don't.
   Use bullets only where order and reference genuinely don't matter (e.g. a
   short set of interchangeable examples).

3. **In long lists, open every entry with a short introductory sentence.**
   When a list has many entries, each one starts with a small plain sentence
   presenting what the entry is about, before the detail. A reviewer scanning
   the list must be able to grasp each entry from its first sentence alone —
   no diving into a wall of `file:line` references or jargon to find out what
   an item concerns.

4. **Make decision points look like decisions.** When the document offers
   more than one way to do something, the formatting must show the reader a
   choice is being asked of them — an explicit "**Decision needed:**" lead-in
   (or equivalent), the alternatives as a numbered sub-list so they can be
   referenced, and your recommendation named if you have one. Never fold
   alternatives into a flat sentence ("do X or do Y.") — that reads as an
   instruction, and the reader sails past the choice instead of making it.

## Example

Entry shape — lead sentence first, detail after:

```markdown
1. **Token flush on every request.** Each widget API call writes
   `last_used_at` synchronously. `ApiTokenAuthenticator.php:45` runs
   `markUsed()` + `flush()` inside the auth path… (detail follows)
```

Not this — starts mid-jargon, fragment-glued, no presentation:

```markdown
1. `ApiTokenAuthenticator.php:45` — markUsed() + flush() on every Bearer
   request · hot-row contention · fix: …
```

Decision shape — the choice is visible and the options referenceable:

```markdown
**Decision needed** — how to stop reset-link host poisoning:

1. Drop `x-forwarded-host` from `trusted_headers` (recommended: smallest
   change, removes the attacker input).
2. Generate emailed links from a pinned `default_uri` instead of request
   context.
```

Not: "Drop `x-forwarded-host` or generate these links from a pinned
`default_uri`." — the reader cannot tell a decision is expected.

## Common mistakes

- Pasting a report drafted for the terminal into `create_document` unchanged —
  terminal formatting habits (dense bullets, `·`-separated fragments, H1
  headers) are exactly what these rules exist to undo.
- Numbering only the top level: nested lists that reviewers will discuss also
  get numbers.
