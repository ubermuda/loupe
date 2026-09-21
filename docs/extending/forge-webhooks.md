---
title: "Forge webhooks"
description: "How Loupe receives what a forge says about a pull request, and turns it into events an agent acts on. Preview, unreleased."
---

Loupe learns nothing from a forge on its own. A merge, a review and a green
check reach a board only because a person reads the forge and drags a card.
A forge webhook closes that gap.

The endpoint is `POST /webhooks/forge/<forge>`, where `<forge>` is `github`,
`gitlab` or `bitbucket`. It is anonymous on purpose. A forge signs its body, or
sends a shared token, rather than carrying a session or a bearer token.

## One adapter for each forge, one vocabulary behind them

An adapter does three things and nothing else. It verifies the delivery, it
decides whether the delivery is a signal the lifecycle uses, and it says so in
Loupe's own words. Everything after that point is forge-blind.

No event name carries a forge, because a rule file and a bridge must not learn
a new event type for each forge an instance connects.

| Event | Meaning |
|---|---|
| `pull_request.review_submitted` | a person submitted a review verdict |
| `pull_request.checks_concluded` | the checks reached an aggregate conclusion |
| `pull_request.merged` | the pull request merged |
| `pull_request.repository_moved` | the repository path changed |

The payload carries identifiers and the forge, never forge-shaped fields. A
review note and a commit message are text a person wrote, and the outbox never
carries that to an agent.

Every event names `system` as its actor, because the fact arrived from outside
Loupe and nobody here judged the card. A bridge older than that actor reads the
event as malformed and drops it, so upgrade the CLI before you connect a forge.

## How a delivery finds a card

`CardPullRequest` stores the repository path and the number of each pull
request a card links. A delivery names both, so a card resolves with no mapping
table of its own.

The path may hold more than one slash. GitLab nests groups, so nothing may
assume two segments.

One pull request can be linked from several cards, and a delivery then concerns
all of them.

## A moved repository

The stored path is the key every later delivery joins on. A rename or a
transfer therefore orphans every card of that repository unless Loupe hears
about it.

`pull_request.repository_moved` repoints those links. Subscribe to whatever
event your forge sends for a rename, a transfer and an owner rename, or the
breakage is silent.

## What the endpoint answers

| Status | When |
|---|---|
| 200 | the delivery is verified, whether or not it named a card Loupe knows |
| 400 | the signature or the token did not verify |
| 404 | no adapter serves that forge, or the board is switched off |
| 429 | more than 300 deliveries in one minute from one address |

Every recognised outcome answers 200, because a forge retries anything else for
days. A delivery about a repository this instance does not know is not an error
to fix.
