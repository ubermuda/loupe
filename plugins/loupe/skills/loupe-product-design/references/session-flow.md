# Session flow

Each level and each phase has a stable ID, so a comment can cite it. The ID P3 is retired. Do not reuse it, so the other IDs keep their meaning.

## Levels

A card does not always need a full session. "Add a Claude design capability to Loupe" needs one. "Cards need tags" needs a much shorter one.

1. L1: Light. Use it for a small addition to an existing feature. Run P0, then P1, P2, P5, P6, P8, P9 and P10. The document keeps the sections that the template lists for a Light document.
2. L2: Full. Use it for a new capability. Run every phase from P0 to P10. The document uses every section of the template.
3. L3: You propose the level. After intake, recommend a level with a one-line reason, and let the owner confirm it. Do not decide from the card title alone, because a small title can hide decisions. "Cards need tags" hides these decisions, and more:
   - Are tags per project, or shared?
   - Who may create a tag?
   - What does a deleted tag do to its cards?
4. L4: You can step up. A Light session can find a decision that Light does not cover. Then tell the owner, and offer to switch to Full. Never step down without the owner's word.

A card that needs no product design does not use this skill. The owner moves it past the Product design column by hand.

## Phases

1. P0: Start. Get the card into the Product design column, with the slug from the profile.
   - With no card, call `card_create` with `status` set to the slug and `reporter` set to `human`. Take the title and the body from the prompt. Set `type` to `feature`, or to the type the prompt names. A create writes no move event, so no bridge rule fires. When `card_create` refuses the slug, create the card with no `status`. Tell the owner that an approval will not move the card.
   - With a card, check its product document first, as `SKILL.md` step 5 says. When that document is approved, stop before any move.
   - When the card sits in the default column, move it to the slug with `card_update` before P1. This move reports your own state. The `default` field of `board_columns` names the default column, and a seeded board calls it `backlog`. When `board_columns` is missing, treat `backlog` as the default column.
   - When the card already sits in the Product design column, leave it there.
   - When the card sits in any other column, stop, and tell the owner.
   - When `card_update` refuses the slug, keep the card where it is. Tell the owner that an approval will not move the card, and go on with P1.
2. P1: Intake. Read the card, the linked documents and the code for the current behaviour before you ask anything. Read the unapproved draft too, when one exists. Then ask for a brain dump with one open prompt. From the brain dump, draft the problem: who feels it, and the situation that triggers the need. Ask one question only when either is unclear. Keep solutions out of the problem.
3. P2: Calibrate. Propose the session level (L3), and let the owner confirm it.
4. P4: Options. When the solution is not obvious, show two or three solution shapes, and recommend one. Skip this phase for a small card.
5. P5: Scope. Fix the first slice, which is the smallest end-to-end slice that works. Fix the no-gos, and what waits for later.
6. P6: Behaviour. Settle the main journeys, the empty and error states, and who may act. Work through the coverage checklist of `question-rules.md`. Ask about docs and the landing page here when the profile is missing.
7. P7: Pre-mortem. Ask one question: "This shipped and failed. Why?" The answers go in "Risks".
8. P8: Acceptance. Draft Given/When/Then scenarios that cite `R` IDs. Let the owner confirm or correct them. They go in "Scenarios".
9. P9: Readback. When no open decision is left, summarise the shared understanding in a few lines. Let the owner confirm it. Write no document before the owner confirms.
10. P10: Write and link. Write the product document, link it to the card, and stop, as `SKILL.md` says. Never move the card, because the owner's approval moves it.

## When to stop asking

Stop when no open decision is left, then run the P9 readback. Use no fixed cap of questions. Put a low-impact unknown in "Open questions" instead of asking more.
