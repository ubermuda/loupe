---
name: loupe-workers
description: "Use when reading worker runs or bridges through the loupe MCP, when calling worker_run_list, worker_run_get, bridge_list, worker_run_resume, worker_run_stop, card_hold, card_release or bridge_command_cancel, or when recovering workers after a usage limit or a network outage stopped them."
---

# Controlling Loupe workers

A bridge runs the workers of a project, and each worker is a run on one card. A resume continues the session of a run as a new run of the same series. The tools act as the project owner, on the bound project only, with the same checks as the worker runs page. No tool pauses a bridge.

## The tools

| Tool | Use it to |
|---|---|
| `worker_run_list` | find runs by `states`, `cardNumber`, `rule`, `bridgeId`, `search`, `endedAfter` and `endedBefore` |
| `worker_run_get` | read one run with its series, output, state changes and commands |
| `bridge_list` | check that each bridge is live and takes commands |
| `worker_run_resume` | resume up to 50 ended runs by `runIds` |
| `worker_run_stop` | stop one queued, resumed, preparing or running run |
| `card_hold` | pause the agents on one card, by `cardId` or `number` |
| `card_release` | let the agents on one paused card run again |
| `bridge_command_cancel` | withdraw the command that waits on one run |

Pass `runId` values from `worker_run_list`, never a card number. Only `card_hold` and `card_release` name a card.

## Recover workers after a usage limit or an outage

1. Call `worker_run_list` with `states` such as `gave-up`, `failed`, `timed-out` and `lost`, and a window around the outage. A timed-out or lost run counts at its first report, so start `endedAfter` before the outage began.
2. Read every page while `hasMore` is true.
3. Read the `reason` of each row, and group the runs by cause yourself. Leave out a run that failed for its own reason.
4. Call `bridge_list`. Each bridge of the runs must show `liveness` `live` and `takesCommands` true.
5. Confirm that the cause is gone, for example that the usage limit reset. When you cannot confirm it, do not resume.
6. Call `worker_run_resume` with the run ids, at most 50 in one call.
7. Read each row of `results`. A resumed row carries `commandId`, and a refused row carries `code` and `message`.

## Refusal codes

| Code | Meaning |
|---|---|
| `not-found` | no run of this project has this id |
| `no-bridge`, `unknown-bridge` | the run has no connected bridge, so tell a person |
| `bridge-outdated` | the bridge cannot take commands, so ask a person to update it |
| `pending` | a command already waits on the run, so wait or cancel it |
| `not-controllable` | the run is an interactive session |
| `no-session` | the run has no session to resume |
| `not-resumable` | the run did not end, or it succeeded |
| `card-left` | the card left the column of the run, so leave the run |
| `card-held` | the agents on the card are paused, so ask a person to let them run |
| `not-stoppable` | the run is not queued, resumed, preparing or running |
| `reason-too-long` | the stop reason is over 1000 characters |
| `nothing-pending` | no command waits, or the bridge already read it |
| `already-paused` | `card_hold` found the agents on the card paused already |
| `card-gone` | `card_hold` found the card deleted |
| `not-paused` | `card_release` found no pause on the card |

## Stop a run

Call `worker_run_stop` with the `runId` and a `reason` that a person reads. A stop ends this run only. A rule can still start new work on the card, because a stop does not pause the agents on it.

## Pause the agents on a card

Call `card_hold` with one of `cardId` or `number`. No bridge then starts a worker on the card. A pause stops no live run, so call `worker_run_stop` as well when a run must end now. A queued run waits, and starts when the pause ends.

Call `card_release` to end the pause. A person ends it too when they select **Let agents run**, move the card to another column, or delete the card. Do not release a pause that you did not set, unless a person asks you to.

## Cancel a command

Call `bridge_command_cancel` with the `runId` to withdraw the waiting resume or stop. An online bridge reads a command in about a second, so a cancel helps mainly while a bridge is offline.
