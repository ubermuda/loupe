# Board drag and the optimistic move

The board reorders cards with a pointer drag. The drop submits a real form, and
the card moves in the DOM before the server answers. These are the rules that
implementation settled.

## The threshold

A gesture becomes a drag at 5 CSS pixels from the press point, measured with
`Math.hypot`. That distance sits above mouse jitter, below what a person reads
as movement, and near what browsers use for their own text drag. Below the
threshold the gesture stays a click and the title link navigates. Above it, the
click that follows the release must be swallowed, or a drag that ends on the
title opens the card it just moved.

These traps around the threshold each cost a cycle:

1. Arm the click swallower only when the release lands inside the board. A click
   fires on the nearest common ancestor of its press and its release. Release
   over the sidebar and the click never reaches a board-scoped listener, so a
   naive flag stays armed. It then eats the next click on the board that is not
   a card, such as the Done history link. Test with
   `this.element.contains(event.target)` at `pointerup`.
2. `pointercancel` must not commit the move. Route it to the `pointerup` handler
   and the browser reclaims the gesture to scroll, then submits a move at the
   cancel coordinates. A touch drag from the card body crosses 5 pixels and the
   scroller then claims it, so the card moves because the reader scrolled.
3. Put `draggable="false"` on the title anchor, or the browser's own link drag
   takes the gesture on the first pixel.

Text selection is handled with `select-none` on the card face, rather than by
arbitration between a selection and the gesture. The cost is that a title cannot
be copied from the board, and the card page keeps it selectable. Keep
`touch-action: none` on the grip alone. On every card it stops a touch reader
scrolling the board at all.

## Optimistic reconciliation needs no diff

`MoveCardController` answers a Turbo submission with one `board-place` stream
for the moved card. The action moves the node that the drag already moved, and
morphs it in place, because the ids match. The column counts come from the
stream. The rest of the board, its scroll and its filter stay as they are. Do
not build a diff.

The stream renders after `turbo:submit-end`, one repaint later. So the
controller keeps the drag lock until `board:placed` or `board:place-missed`
names the card. A drag that starts in that window marks a place the placement
then shifts.

## One failure hook

`turbo:submit-end` fires in all three terminal states. Verified in
`assets/vendor/@hotwired/turbo/turbo.index.js`: `requestErrored` sets
`this.result = {success: false, error}`, and `requestFinished` runs in a
`finally` and dispatches `turbo:submit-end` with the result spread into
`detail`. One listener covers a non-2xx answer, a network failure and a success.

The paths as implemented:

- A refused move is a 422 with no body, and no flash. The card goes back.
- Any other answer that is not a 2xx stream, such as a 404 error page, is
  stopped at `turbo:before-fetch-response` with `preventDefault()`, so Turbo
  renders no page. The card goes back.
- A lost connection ends with `success` false, and the card goes back.
- A request that does not accept a stream, such as the card page's own move
  form, still gets a 302 and the flash.
- A request that never answers leaves dragging refused until a reload.

## Restore by following card, never by index

Record the card element that followed the dragged one, then call
`originNextCard.before(card)`. Fall back to `group.append(card)` when the
dragged card was last. Restoring by index is wrong as soon as anything else has
moved. Guard both calls with `isConnected`, because the board may have been
replaced.

## Concurrency is a judgement call

A second drag is refused while one is in flight. The pending card is dimmed and
carries `aria-busy` until its placement lands. The spec waits for no card to
carry `aria-busy` before a second drag. Allowing a second drag costs something on both sides.
The second card's rank is computed against a position the server has not
accepted. If the first answer lands mid-gesture, its placement can shift the
drop marker under the pointer. The owner may prefer the other
side, so treat the refusal as a decision rather than a requirement.

## Structural facts about the controller

The controller is `stimulusFetch: 'eager'` and publishes
`data-board-drag-ready`. A lazily fetched controller leaves a window in which a
grab reaches no listener. The spec waits on that attribute and hangs without it.

The drop submits through a real per-card `<form>`, built with `createNamed` and
hidden on the card face. That form is what keeps Turbo carrying the request and
the eager CSRF controller stamping the token. Removing it means re-implementing
both by hand.

## The test race the optimistic move introduces

A reload assertion that proves the order persisted used to be safe, because the
poll could not pass until the stream re-rendered. With an optimistic move the
poll passes at once, so `page.goto` can race the write. Both drag specs take
`page.waitForResponse(r => r.url().endsWith('/move'))` before they assert.
Without that wait the spec passes on a quiet stack and fails on a loaded one.
