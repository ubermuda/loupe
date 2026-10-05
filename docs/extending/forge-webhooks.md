---
title: "Forge webhooks"
description: "How Loupe receives what a forge says about a pull request, and turns it into events an agent acts on. Preview, unreleased."
---

A forge webhook tells Loupe about a merge, a review and a check result. Loupe
then writes an event for each card that links the pull request. For a
repository connected through the GitHub App, Loupe also moves the card on green
checks and on a merge. [What GitHub tells a card](../using/board.md#what-github-tells-a-card)
describes the moves. Without a webhook, a person reads the forge and moves the
card.

A project owner connects repositories on the Connections tab of the project.
[Projects](../using/projects.md#repositories) describes that page. This page
describes the contract behind it, and what the operator sets up for GitHub.

## The Forge module and a forge module

The `Forge` module holds what every forge shares: repository ownership, the
neutral event vocabulary and the rate limit. A forge module, such as `GitHub`,
verifies a delivery and translates it. The `Board` module reads the result and
knows no forge.

A forge module writes ownership through `ForgeRepositories` only.

| Method | Does |
|---|---|
| `claim($project, $forge, $externalId, $path, $source, $sourceRef)` | Creates or confirms the row of the project. It returns `Owned`, `AlreadyOwned` or `Refused`. A `Hook` claim never refuses. An `Installation` claim refuses when another project holds an installation row, and `Refused` names no owner. When the project claims under a new path, the row takes the new path and the claim reports the old one. |
| `release($project, $forge, $externalId)` | Removes the row of the project. |
| `releaseInstallation($forge, $sourceRef, $externalId)` | Removes the rows of one installation, or its row for one repository. |
| `accepted($repository, $now)` | Records the time of the last accepted delivery, at most once a minute. The repository state on the Connections tab reads it. |
| `installationOwnerOf($forge, $externalId)` | Reads the installation row of a repository. |

Each write flushes. A `claim()` that throws closes the EntityManager, because
it runs in a transaction.

After a claim that is not `Refused`, the forge module dispatches
`ForgeDeliveryReceived`. It carries the id of the claiming project, a
non-empty list of `ForgeDelivery`, and the `ForgeRepositorySource` of the
claim. A listener acts inside that project only.

A route that receives deliveries sets two defaults, and the rate limiter reads
them. `_forge_webhook: true` marks the route. `_forge_webhook_key` selects the
key: `address` for the client address, or `hook-key` for the `hookKey` path
segment.

## One vocabulary for every forge

No event name carries a forge, because the workflow and the activity feed must
not learn a new event type for each forge an instance connects.

The payload carries identifiers and the forge, and no field in the shape of one
forge. A review note and a commit message are text a person wrote, and the
outbox never gives that text to an agent.

Every event names `system` as its actor, because the fact arrived from outside
Loupe and nobody here judged the card. The events feed the activity feed and
the workflow of the board. No bridge receives them.

### Events from an App repository

A repository that an App installation feeds gets its events from
[state reads](#pull-request-state). Loupe compares each read with the stored
state, and writes an event for each change. A review delivery writes
`review_submitted` with the verdict of the review that GitHub sent. It does so
also when the branch requires no review. Each event is a fact about the pull
request. The [workflow](../using/workflows.md) of the board reads the stored
state of the pull request, and decides what to do.

| Event | Kind | When | Extra fields |
|---|---|---|---|
| `pull_request.checks_concluded` | fact | the required checks passed or failed, and the conclusion or the checked commit changed | `conclusion`, `passed` or `failed`. `failedChecks`, the names of the failed required checks, empty on `passed` |
| `pull_request.conflicted` | fact | the pull request now conflicts with its base | none |
| `pull_request.behind` | fact | the branch is now behind its base | none |
| `pull_request.review_submitted` | fact | a person approved or requested changes. A comment review sends nothing | `verdict`, `approved` or `changes-requested` |
| `pull_request.merged` | fact | the pull request merged | none |
| `pull_request.closed` | fact | the pull request closed without a merge | none |

Every event carries `cardId`, `cardNumber`, `forge`, `repository`,
`pullRequestNumber`, `pullRequestUrl` and `headSha`. Loupe leaves out a
repository, a URL or a head commit that does not have a strict shape.

A fact goes to every card that links the pull request, in any column. The
pull request events named `pull_request.fix_requested` and
`pull_request.ready_to_merge` are gone. The workflow asks a bridge for a fix or
a merge itself, as its template says. The Lifecycle template gives a card 3 fix
rounds at most, and then pauses it.

### Events from a repository with no state reads

A state read needs an installation token. So two kinds of repository get no
state reads: one that a per-project webhook feeds, and one that the App feeds
while `GITHUB_APP_ID` or `GITHUB_APP_PRIVATE_KEY` is unset. Their deliveries
give three bare events: `pull_request.review_submitted`,
`pull_request.checks_concluded` and `pull_request.merged`. Each carries
`cardNumber` and `forge`, and none of the other pull request fields. A bridge
rule with `when` never matches one, because the field it reads is absent. Such
a repository gets no decisions.

### When an event reaches the bridge

Loupe writes a bare event in the request that receives the delivery. It
publishes the event when that request ends. A state read runs in the messenger
worker, on the `async` transport. The worker publishes the rows it writes when
it finishes the message. The outbox drain runs every five minutes, and it
publishes a row that the first attempt missed.

## Ownership

Loupe keys a repository on the forge and on the stable id the forge gives it,
which is `repository.id` on GitHub. A path is not a key, because a path
changes. Each project that receives a repository holds its own row.

Only an App installation makes a repository exclusive. The owner of a project
knows the secret of its webhook, so that owner can sign any body and name any
repository. A webhook claim therefore feeds its own project and blocks nobody.
An App claim is exclusive, because GitHub proves the installation through the
user token and the App secret. An App delivery for a repository that the App of
another project holds is dropped. The endpoint still answers 200, and the log
names the claiming project only.

`CardPullRequest` stores the repository path and the number of each pull
request that a card links. A delivery names both, so it finds its cards with no
separate mapping table. Only the cards of the project that received the
delivery match. Another project can link the same pull request, and gets
nothing.

A rename or a transfer keeps the id, so the owner stays the same. The first
delivery under the new path moves the row. The forge module then maps it to a
`pull_request.repository_moved` delivery, which comes before the other facts.
The board repoints the pull request links of that project only, for both
sources, and records the move in the audit log. It writes no outbox event for
a move.

## The GitHub routes

| Route | Signed with | Rate limit key |
|---|---|---|
| `POST /webhooks/forge/github/{hookKey}` | the secret of that project's webhook | the hook key |
| `POST /webhooks/forge/github` | `GITHUB_APP_WEBHOOK_SECRET` | the client address |

Both routes are anonymous, because GitHub signs the body and sends no token.
Each allows 300 deliveries a minute for one key. GitHub delivers for every
customer from one shared pool of addresses, so a per-project webhook uses its
hook key as the key.

The per-project webhook claims each repository it reports for its project. The
App route reads the installation id in the body, and the installation names the
project. A delivery with no installation, or for an installation Loupe does not
know, is dropped.

| Status | When |
|---|---|
| 200 | the delivery verified. This includes a dropped delivery and an event Loupe does not use. |
| 400 | the signature did not verify, the secret is empty, or the body is not JSON |
| 404 | no webhook has that hook key |
| 429 | the key sent too many deliveries in one minute: 3000 for the App route, 300 for one webhook |

Every recognised outcome answers 200, so the GitHub delivery log shows a
failure only when something is wrong. GitHub does not send a failed delivery
again on its own. A person can send it again from the delivery log.

## Registering the GitHub App

A per-project webhook needs nothing from the operator except
`APP_ENCRYPTION_KEY`, which encrypts its secret. The GitHub App is optional.
Register it on GitHub under Settings, Developer settings, GitHub Apps.

| Setting | Value |
|---|---|
| Callback URL | `https://<host>/github/app/callback` |
| Request user authorization (OAuth) during installation | off |
| Setup URL | `https://<host>/github/app/setup` |
| Redirect on update | off |
| Webhook | active |
| Webhook URL | `https://<host>/webhooks/forge/github` |
| Webhook secret | the value of `GITHUB_APP_WEBHOOK_SECRET` |

Grant these repository permissions. Pull requests and Contents are read and
write. The others are read-only. Loupe needs write access to Pull requests to
post the fix-run comment, and to mark an epic pull request ready, convert it to
draft or close it. It needs write access to Contents to sync a branch that is
behind its base.

| Permission | Why |
|---|---|
| Pull requests (read and write) | the merge and the review verdict; write lets Loupe post the fix-run comment, and mark an epic pull request ready, draft or closed |
| Checks | the aggregate check conclusion |
| Contents (read and write) | GitHub offers the Push event only with it; write lets Loupe sync a pull request branch with its base |
| Commit statuses | the status of each check context |
| Metadata | GitHub requires it, and it carries the Repository event |

An installation that exists before this change keeps its old permissions.
When you change a permission to read and write, the owner of each
installation must accept the new permission on GitHub. Until then,
`/admin/status` shows a warning that names each account that has not accepted
it.

Subscribe to these events. The permissions decide which events GitHub offers.
GitHub sends the installation events without a subscription.

| Event | Why |
|---|---|
| Pull request | the merge arrives as `closed` with `merged` true, and a push to the branch arrives as `synchronize` |
| Pull request review | the review verdict |
| Check suite | the aggregate conclusion of a run |
| Check run | the conclusion of one check |
| Commit status | the state of a check that reports through the Statuses API |
| Push | a push to a base branch can make a pull request conflict |
| Repository | a rename or a transfer changes the path |

A merge has no event of its own. The lifecycle events come from the check
suite, because a check run fires once for each check.

### Pull request state

Loupe keeps the state of each GitHub pull request that a card links: open,
merged or closed, the head commit, the checks, the mergeability and the review.
Only a repository connected through the App gets this state, because Loupe
reads it with the installation token.

A delivery is only a hint. Each pull request, check suite, check run, commit
status or review delivery makes the worker read the pull request again. A push
to a branch makes the worker read each open pull request with that base branch,
30 seconds later, because GitHub computes a conflict after the push. While
GitHub still computes the mergeability, the worker reads again after 30, 60, 120
and 240 seconds.

The checks verdict reads only the required checks of the base branch. Loupe
takes them from the branch rules of the repository and from the checks that
GitHub marks as required on the head. A required check that has not started yet
counts as pending. When the rules require an up-to-date branch,
Loupe compares the head with the base to find a branch that is behind.

`app:sweep-forge-pull-requests` reads each open pull request again every ten
minutes, so a lost delivery changes nothing for long.

Then set the four install variables.
[Environment variables](../reference/environment.md) describes each one.

- `GITHUB_APP_SLUG`, the last segment of `https://github.com/apps/<slug>`
- `GITHUB_APP_CLIENT_ID`
- `GITHUB_APP_CLIENT_SECRET`
- `GITHUB_APP_WEBHOOK_SECRET`

Set all four or none. While one is empty, the App is not offered, and the
*GitHub App* row on `/admin/status` names the empty variables.

A separate optional pair lets Loupe sign as the App and read repositories
through an installation token. The install does not need it.

- `GITHUB_APP_ID`, the App ID on the *General* page of the App settings
- `GITHUB_APP_PRIVATE_KEY`, a private key that you generate on the same page.
  Paste the PEM file as it is, or on one line with `\n` for each line break.

Set both or neither. The *GitHub App access* row on `/admin/status` reports a
key that GitHub refuses, and an installation that misses a permission.

A repository connected through a per-project webhook gets no pull request
automation, because Loupe has no installation token for it. An App without this
pair gets none either, and its repositories get the bare events.

The install runs in this order. The owner selects Install on GitHub, and GitHub
shows its install page. GitHub then sends the owner to the setup URL. Loupe asks
GitHub to authorize the user, with a state and PKCE. The callback accepts the
installation only when `GET /user/installations` lists it for that user.

## Open questions to verify against a real App

Nobody has run these cases against a registered App yet. The code works
whatever each answer is.

- Does GitHub send `state` back to the setup URL after an install? Loupe checks
  it when it arrives, and relies on the session when it does not.
- Does a new repository under an "All repositories" installation send
  `installation_repositories`? If not, Loupe claims the repository on its first
  delivery.
- Does the `ping` of a repository webhook carry `repository`? Loupe claims
  nothing from a `ping` in either case.

## Upgrading from the instance-wide secret

An earlier version verified every delivery with one instance secret,
`GITHUB_WEBHOOK_SECRET`. Loupe no longer reads it. The old URL,
`/webhooks/forge/github`, now receives the App and verifies with
`GITHUB_APP_WEBHOOK_SECRET`. A delivery from an old webhook therefore reaches
no card after the upgrade. Its signature fails, or it names no installation. Each project owner must connect
the project's repositories again, with a webhook or with the App. Then remove
the old webhook from each repository on GitHub.
