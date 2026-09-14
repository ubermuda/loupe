# The review round

A stage skill sends you here from its revise branch. The skill names two things: the document, such as `product document` or `tech design`, and the requirement source, such as the card body. Read `loupe-documents` rules 6, 7 and 12 first.

## Decide whether to revise

Read `document_get_review`. Act when at least one of these holds:

1. A root comment has `status` `pending`.
2. The `verdict` is `changes-requested`.
3. An entry in `decisions` has an answer, and the document has no `**Decided:**` line for it.
4. The requirement source holds a requirement the document does not cover yet.

When none holds, change nothing. Stop with `STAGE RESULT: <document> unchanged`.

## Answer every pending comment

Reply to every `pending` root comment, and mark it addressed. Do this for a comment you answer with no change too. Finish the replies before you call `document_revise`, because a revision invalidates every comment id (rule 7).

1. Apply a comment's `replacement` when it has one. Its `quote` is the exact span to substitute. An empty `replacement` deletes the span.
2. Reply to an `orphaned` comment. Never guess where its text belongs.
3. Leave the text of a section alone when its `standing_approval_count` is above 0, because a rewrite drops the approval. When a comment asks for a change there, reply and say that the section is approved.

## Revise only when the Markdown changes

1. Add a `**Decided:**` line for each answered decision that has none (rule 6). Keep every fence id, because a changed id discards the answer.
2. Cover each new requirement from the requirement source.
3. When the Markdown is different, call `document_revise` with a `description` that names what changed (rule 9). Stop with `STAGE RESULT: <document> revised <id>`.
4. When the Markdown is the same, send no version. Stop with `STAGE RESULT: comments answered`.
