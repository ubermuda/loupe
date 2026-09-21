# The review round

A stage skill sends you here from its revise branch. The skill names two things: the document, such as `product document` or `tech design`, and the requirement source, such as the card body. Read `loupe-documents` rules 6, 7 and 12 first.

When a review field is absent, such as `standing_approval_count`, `replacement`, `orphaned` or `decisions`, treat it as empty and go on.

## Decide whether to revise

Read `document_get_review`. Act when at least one of these holds:

1. A root comment is open. A root is open when its `status` is `pending`. A root that is not `resolved` is open too, when its thread holds a reply with `author` `human` after the latest reply with `author` `agent`. Marking a comment addressed does not reset that test.
2. The `verdict` is `changes-requested`.
3. An entry in `decisions` has an answer, and no `**Decided:**` line names that answer. A single-choice entry answers in `selected`. A multiple-choice entry answers in `selections`. A `**Decided:**` line that names an older answer does not count.
4. The requirement source holds a requirement the document does not cover yet.

When none holds, change nothing. Stop with `STAGE RESULT: <document> unchanged`.

## An approved section wins

A section whose `standing_approval_count` is above 0 is approved. Never change its text, because a rewrite drops the approval. This rule wins over every rule below.

## Answer every open comment

Handle every open root comment, including a comment you answer with no change. Write down the reply for each one, and post the replies last, as "Revise, then reply" says.

1. Apply a comment's `replacement` when it has one. Its `quote` is the exact span to substitute. An empty `replacement` deletes the span.
2. When the `replacement` falls inside an approved section, do not apply it. Reply, and say that the section is approved.
3. Answer an `orphaned` comment with a reply only. Never guess where its text belongs.

## Revise, then reply

1. Add a `**Decided:**` line for each answered decision that has none (rule 6). Keep every fence id, because a changed id discards the answer.
2. When the current answer differs from the option a `**Decided:**` line names, rewrite that line. Rewrite every requirement and decision the change affects too. This counts as a text change.
3. When the fence sits inside an approved section, put its `**Decided:**` line in the document's `Decided` section. When the document has none, add a `## Decisions` heading at the end.
4. Cover each new requirement from the requirement source.
5. When the Markdown is different, call `document_revise` first, with a `description` that names what changed (rule 9). Then call `document_get_review` again, because a revision gives every comment a new id (rule 7, order 2). Reply to each handled comment through its new id, and mark it addressed. Stop with `STAGE RESULT: <document> revised <id>`.
6. When the Markdown is the same, send no version. Reply to each handled comment and mark it addressed directly. When you replied to at least one, stop with `STAGE RESULT: comments answered`.
7. When the Markdown is the same and you replied to no comment, stop with `STAGE RESULT: <document> unchanged`.

Revise before you reply, because a crash between the replies and the revision leaves comments marked addressed whose correction never landed.
