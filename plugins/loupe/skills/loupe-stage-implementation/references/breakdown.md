# The Breakdown section

A tech design splits a card that is too big for one worker into child cards. The tech design stage writes the section. The implementation stage reads it and creates the children. Both stages read this file.

## The format

1. The section is the `##` heading `Breakdown`, with a numbered list.
2. Each entry is one child card. It has a stable ID, a title, what it covers, and its blockers:

   ```markdown
   1. **B3: Add swimlanes to the board.** Covers D5 and D6. Blocked by: B1, B2.
   ```

3. The ID is `B` and a number. The text after the ID, without the final period, is the title of the child.
4. `Covers` names the design sections that the child builds. The child builds those sections and nothing else.
5. `Blocked by` names the IDs of the entries that must finish first. Write `Blocked by: none` for an entry with no blocker.
6. An ID never changes across revisions. Give a new entry the next unused number. Never reuse the ID of a removed entry.
7. Keep the blockers free of loops. A child in a loop never starts.

## The entry line of a child

The entry line opens the body of each child and names its entry:

```markdown
Breakdown item B3 of card #214.
```

`#214` is the number of the epic. A parked child keeps its `**Parked.**` line above this line. A rerun matches a child to its entry by this line. When no child has the line, it matches by the exact title.

## Run the breakdown

The breakdown changes the board only. It creates no worktree and writes no code. `<design>` is the approved tech design, and `<default>`, `<implementation>` and `<terminal>` are the slugs of the profile `Board` section.

1. Note the type of the card. When it is not `epic`, set the type `epic` with `card_update`. `card_create` refuses a parent that is not an epic, so this step comes first.
2. Read every page of `card_list` with `parentCardId` set to the card id and `full` set, so each row carries its body. Match each entry of the Breakdown section to a child, by the entry line, then by the exact title. Leave a child that matches no entry as it is. A child that matches by title only has no entry line. Add the entry line to the top of its body with `card_update`, and send the rest of the body unchanged. When the body opens with `**Parked.**`, keep that line first and put the entry line after it.
3. For each entry with no child, call `card_create` with no `status`, so the child lands in `<default>`. Send the title of the entry, the entry line and the whole entry as the body, the card id as `parentCardId`, and `<design>` in `documentIds`. The type is the type step 1 noted, or `feature` when that type was `epic`.
4. Set the blockers in a second pass, because an entry can name a child that step 3 creates later. For each child whose entry names blockers, read the child with `card_get` just before the write. Send its `relatedCards` back, plus a `blocked-by` entry for each blocker that it does not already carry. `relatedCards` replaces every link of the card, so never send the new entries alone.
5. Move each child that matches an entry, sits in `<default>` and has no open blocker to `<implementation>` with `card_update`. A blocker is open when its `status` is not `<terminal>`. Skip a child whose body opens with `**Parked.**`, because the owner paused it. These moves are the only moves the breakdown makes. The epic stays in its column.

The result line is `STAGE RESULT: breakdown <n> children, <m> started`. `<n>` is the number of children the epic has after step 3, and `<m>` is the number of children step 5 moved.

A design with no Breakdown section gives the epic no entries. The run then creates nothing and moves nothing.

Step 5 also moves a child that a person put back in `<default>` on purpose, when it has no open blocker and is not parked. That is an accepted cost of a rerun. Park the child to keep it in `<default>`.

## Build a child

1. A child uses the tech design of its epic. The breakdown links that design to the child, and a child skips product design and tech design.
2. The entry line of the body names the entry. Find the entry with that ID in the Breakdown section of the design.
3. When the body names no entry, or the design has no entry with that ID, stop with `STAGE RESULT: blocked: no breakdown item`.
4. The plan, the code and the pull request cover only the design sections that the entry covers. Another child builds the rest.
