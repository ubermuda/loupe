---
title: "Forge webhooks"
description: "How Loupe receives what a forge says about a pull request, and turns it into events an agent acts on. Preview, unreleased."
---

A forge webhook tells Loupe about a merge, a review and a check result. Loupe
then writes an event for each card that links the pull request. Without it, a
person reads the forge and moves the card.

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
`ForgeDeliveryReceived`. It carries the id of the claiming project and a
non-empty list of `ForgeDelivery`. A listener acts inside that project only.

A route that receives deliveries sets two defaults, and the rate limiter reads
them. `_forge_webhook: true` marks the route. `_forge_webhook_key` selects the
key: `address` for the client address, or `hook-key` for the `hookKey` path
segment.

## One vocabulary for every forge

No event name carries a forge, because a rule file and a bridge must not learn
a new event type for each forge an instance connects. `ForgeEventType` holds
four values.

| Event | Meaning |
|---|---|
| `pull_request.review_submitted` | a person submitted a review verdict |
| `pull_request.checks_concluded` | the checks reached an aggregate conclusion |
| `pull_request.merged` | the pull request merged |
| `pull_request.repository_moved` | the repository path changed |

The payload carries identifiers and the forge, and no field in the shape of one
forge. A review note and a commit message are text a person wrote, and the
outbox never gives that text to an agent.

Every event names `system` as its actor, because the fact arrived from outside
Loupe and nobody here judged the card. A bridge older than that actor reads the
event as malformed and drops it, so upgrade the CLI before you connect a forge.

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
delivery under the new path moves the row, and Loupe writes
`pull_request.repository_moved` before the other facts in that delivery. That
event repoints the pull request links of that project only.

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
| 200 | the delivery verified. This includes a dropped delivery, an event Loupe does not use, and a project whose board is off. |
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

Grant these repository permissions, all read-only.

| Permission | Why |
|---|---|
| Pull requests | the merge and the review verdict |
| Checks | the aggregate check conclusion |
| Metadata | GitHub requires it, and it carries the Repository event |

Subscribe to these events. The permissions decide which events GitHub offers.
GitHub sends the installation events without a subscription.

| Event | Why |
|---|---|
| Pull request | the merge arrives as `closed` with `merged` true |
| Pull request review | the review verdict |
| Check suite | the aggregate conclusion of a run |
| Repository | a rename or a transfer changes the path |

A merge has no event of its own. A check run fires once for each check, so
Loupe reads the check suite instead.

Then set four variables. [Environment variables](../reference/environment.md)
describes each one.

- `GITHUB_APP_SLUG`, the last segment of `https://github.com/apps/<slug>`
- `GITHUB_APP_CLIENT_ID`
- `GITHUB_APP_CLIENT_SECRET`
- `GITHUB_APP_WEBHOOK_SECRET`

Set all four or none. While one is empty, the App is not offered, and the
*GitHub App* row on `/admin/status` names the empty variables.

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
