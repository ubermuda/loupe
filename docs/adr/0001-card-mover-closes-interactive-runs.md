---
title: "0001: The card mover closes interactive runs"
description: "Why a card move closes the card's interactive runs inside CardMover, and not in each handler."
---

## Status

Accepted on 2026-09-24.

## Context

An interactive run records a person's Claude session that works on a card in
its current column. When the card leaves that column, the session no longer
works on that stage, so its run must close.

Two code paths move a card to another column:

- `CardMover::move()` moves one card. `UpdateCardHandler` calls it, and
  `MoveCardHandler` goes through `UpdateCardHandler`.
- `CardRepository::moveAll()` moves every card of a column in one SQL
  statement, when `DeleteBoardColumnHandler` deletes that column.

The first version closed the runs in each handler. A new move path had to
remember the close. When it forgot, no test failed, and the runs stayed open on
a card that had moved on.

## Options

### Close in each handler

This is the first version. It needs no new code. Each new caller must still
remember the close, and nothing tells it to.

### A Bridge listener on the board events

A listener in the Bridge module closes the runs when a card moves. Board then
imports nothing from Bridge for this. There are two costs:

- The column delete dispatches no `CardMoved`, on purpose, because the outbox
  must not see one event per card. A second listener on `BoardColumnDeleted` is
  necessary. A new move path must still dispatch one of the two events.
- One update can move a card and open a run. `CardMoved` fires after the open,
  so the listener closes the new run unless it filters that run out. The outbox
  listener reads `hasOpenRun()` from the same event, so the order of the two
  listeners also decides the payload.

### The mover owns the close

`CardMover` is the one place that knows a card changes column. The close goes
there, for the single move and for the bulk move.

## Decision

`CardMover` owns the close.

- `CardMover::move()` closes the open interactive runs of the card when the
  card changes column. It closes before it changes the card, because the close
  flushes.
- `CardMover::moveAll()` wraps `CardRepository::moveAll()` and closes the runs
  of every card it moves. `CardRepository::moveAll()` returns the ids of the
  moved cards.
- `UpdateCardHandler` and `DeleteBoardColumnHandler` move through the mover
  only. `UpdateCardHandler` opens a new run after the move, so the move does not
  close it.

## Consequences

- A new move path that goes through `CardMover` closes the runs with no extra
  code. Tests in `CardInteractiveRunTest` call the mover directly, so a change
  that removes the close fails there.
- `CardMover` now writes to the database. The close runs inside the caller's
  transaction, so it rolls back with the move.
- `CardMover` imports `InteractiveRuns` from the Bridge module. Board handlers
  already imported it, and phparkitect allows the edge.
- Nothing stops code that sets `Card::column` itself, or that calls
  `CardRepository::moveAll()` directly. The docblock of `moveAll()` points to
  the mover. No static check enforces it.
