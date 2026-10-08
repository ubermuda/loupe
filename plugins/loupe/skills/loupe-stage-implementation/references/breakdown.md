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
5. `Blocked by` names the IDs of the entries that must finish first. Write `Blocked by: none` for an entry with no blocker. A blocker can also name a decision of the design, such as `D2`. The run of the child checks that decision, and the blocker is closed when the decision has an answer, as `../../loupe-stage-product-design/references/stage-contract.md` "Read the answers of a design" says. A decision blocker gets no `blocked-by` link.
6. An ID never changes across revisions. Give a new entry the next unused number. Never reuse the ID of a removed entry.
7. Keep the blockers free of loops. A child in a loop never starts.

## The entry line of a child

The entry line opens the body of each child and names its entry:

```markdown
Breakdown item B3 of card #214.
```

`#214` is the number of the epic. A parked child keeps its `**Parked.**` line above this line. A rerun matches a child to its entry by this line first, and that match is certain. A child with no entry line matches by judgement. Read the title and the body of the child against the text of the entry and the design sections it covers.

1. A match is sure when one child plainly covers one entry.
2. A match is unsure when two children seem to cover one entry, or one child seems to cover two entries. It is also unsure when you cannot tell.
3. A child that links a tech design other than the design of the epic is standalone. It matches no entry by judgement.
4. A child that no entry covers stays as it is.
5. A matched child keeps its title. The breakdown adds only the entry line, the entry and the link to the design.

## Run the breakdown

The breakdown changes the board, and the remote branches when the profile has an `Epics` section. It writes no code and no file. `<design>` is the approved tech design, and `<default>`, `<implementation>` and `<terminal>` are the slugs of the profile `Board` section.

1. Read every page of `card_list` with `parentCardId` set to the card id and `full` set, so each row carries its body and its `pullRequests`. Match each entry of the Breakdown section to a child, as "The entry line of a child" says.
2. When a match is unsure, load the `loupe-inbox` instruction. Read the answers of your earlier asks with `inbox_list` and `readerSessionId`, as `loupe-inbox` says. A child that the owner picks matches the entry. An entry for which the owner picks no child gets a new child. An item that closed with no clear answer stays unsure.
3. When a match is still unsure after step 2, make one `inbox_ask` with one item for each unsure entry. Each item names the entry and its candidate children, by number and title. Pass your session id and the bridge id of the worker. Then stop with `STAGE RESULT: blocked: breakdown match needs the owner`. The first sentence after it is "Answer the inbox ask." Record no block on the card, because the ask is the record. A resumed run starts again at step 1. The blocked run settles its work request, so an epic whose children are all finished can move to `in-review` or `<terminal>` while the ask is open. The column check of `SKILL.md` step 3 accepts those two moves for a resumed run, and stops on any other column. A child that the resumed run creates brings the epic back to `<implementation>`. Steps 1 to 3 change no card and no branch, so the breakdown waits until every unsure match has an answer.
4. Note the type of the card. When it is not `epic`, set the type `epic` with `card_update`. `card_create` refuses a parent that is not an epic, so this step comes before step 7.
5. Read each matched child with `card_get` just before the write. With one `card_update`, add `<design>` to its `documentIds`, and send back every id it already carries. A child that links `<design>` already needs no change there. Add the entry line and the whole entry to the top of the body of each matched child that has no entry line. Send the rest of the body unchanged. When the body opens with `**Parked.**`, keep that line first and put the entry line after it.
6. When the profile has an `Epics` section, create the epic branch before you create a child. Fill the epic branch pattern of that section with the number of the card. `<base>` is the base branch of the profile `Gate` section. Run this from the main checkout:

   ```bash
   git ls-remote --exit-code origin refs/heads/<epic branch>
   ```

   Exit 0 means the branch exists, so push nothing. When the branch does not exist and no child of the epic has an entry in `pullRequests`, push it:

   ```bash
   git fetch origin <base>
   git push origin origin/<base>:refs/heads/<epic branch>
   ```

   When the push fails, stop with `STAGE RESULT: blocked: epic branch push refused: <message>`. An epic with no epic branch and a child that links a pull request started on the old flow. Push nothing for it, so its children keep the profile base branch.
7. For each entry with no child, call `card_create` with no `status`, so the child lands in `<default>`. Send the title of the entry, the entry line and the whole entry as the body, the card id as `parentCardId`, and `<design>` in `documentIds`. The type is the type step 4 noted, or `feature` when that type was `epic`.
8. Set the blockers in a second pass, because an entry can name a child that step 7 creates later. For each child whose entry names blockers, read the child with `card_get` just before the write. Send its `relatedCards` back, plus a `blocked-by` entry for each entry blocker that it does not already carry. Skip a decision blocker. `relatedCards` replaces every link of the card, so never send the new entries alone.

The breakdown moves no child. The workflow of the board starts a child that links an approved tech design and has no open blocker. The result line is `STAGE RESULT: breakdown <n> children`. `<n>` is the number of children the epic has after step 7.

After the result line, list each match on its own short line: the entry ID, the child number, and the reason. The reason is `entry line`, `judgement` or `owner`. Then list each child that step 7 created. The bridge keeps only the first 4 KB of the reply, so write nothing else on a line:

```text
B1: #231 (entry line)
B2: #232 (judgement)
B3: #240 (owner)
Created: B4 #245, B5 #246
```

A design with no Breakdown section gives the epic no entries. The run then creates nothing and links nothing.

## Build a child

A Breakdown child has the entry line in its body. A standalone child has none, for example a card that a person moved under an epic by hand, or a card that the owner answered with "Link the tech design of the epic". The epic still groups it on the board. To find the design of the epic, call `card_get` on `parent.cardId` and read its linked tech design.

1. A Breakdown child uses the tech design of its epic. The breakdown links that design to the child, whether it created the child or matched it, and a Breakdown child skips product design and tech design.
2. The entry line of the body names the entry. Find the entry with that ID in the Breakdown section of the design.
3. When the design has no entry with that ID, stop with `STAGE RESULT: blocked: no breakdown item`.
4. The plan, the code and the pull request cover only the design sections that the entry covers. Another child builds the rest.
5. A standalone child that links a tech design of its own builds that design, as any other card. It never builds the design of its epic.
6. A standalone child that links only the approved tech design of its epic is an epic-design child. The card body is the requirement. The design of the epic is the frame: the plan follows its architecture and its decided items, and references it. The plan covers only the work that the card body describes. Another child builds the rest.
7. When the card body asks for something that the design of the epic contradicts, stop with `STAGE RESULT: blocked: card body conflicts with the epic design`. Name the conflict in the sentences after it.
8. A standalone child that links no approved tech design stops with `STAGE RESULT: blocked: needs its own tech design: move the card to Tech design`.
