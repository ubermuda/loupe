# The review round

A stage skill sends you here from its revise branch. The skill names two things: the document, such as `product document` or `tech design`, and the requirement source, such as the card body. Read `loupe-documents` rules 6, 7 and 12 first.

When a review field is absent, such as `standing_approval_count`, `replacement`, `orphaned` or `decisions`, treat it as empty and go on.

## Decide whether to revise

Read `document_get_review`. Act when at least one of these holds:

1. A root comment has `status` `pending`.
2. The `verdict` is `changes-requested`.
3. An entry in `decisions` has an answer, and the document has no `**Decided:**` line for it. A single-choice entry answers in `selected`. A multiple-choice entry answers in `selections`.
4. The requirement source holds a requirement the document does not cover yet.

When none holds, change nothing. Stop with `STAGE RESULT: <document> unchanged`.

## An approved section wins

A section whose `standing_approval_count` is above 0 is approved. Never change its text, because a rewrite drops the approval. This rule wins over every rule below.

## Answer every pending comment

Reply to every `pending` root comment, and mark it addressed. Do this for a comment you answer with no change too. Finish the replies before you call `document_revise`, because a revision invalidates every comment id (rule 7).

1. Apply a comment's `replacement` when it has one. Its `quote` is the exact span to substitute. An empty `replacement` deletes the span.
2. When the `replacement` falls inside an approved section, do not apply it. Reply, and say that the section is approved.
3. Reply to an `orphaned` comment. Never guess where its text belongs.

## Revise only when the Markdown changes

1. Add a `**Decided:**` line for each answered decision that has none (rule 6). Keep every fence id, because a changed id discards the answer.
2. When the fence sits inside an approved section, put its `**Decided:**` line under a `## Decisions` heading at the end of the document.
3. Cover each new requirement from the requirement source.
4. When the Markdown is different, call `document_revise` with a `description` that names what changed (rule 9). Stop with `STAGE RESULT: <document> revised <id>`.
5. When the Markdown is the same and you replied to at least one comment, send no version. Stop with `STAGE RESULT: comments answered`.
6. When the Markdown is the same and you replied to no comment, stop with `STAGE RESULT: <document> unchanged`.
