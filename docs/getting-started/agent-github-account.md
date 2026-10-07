---
title: "Agent GitHub account"
description: "Give the agents of a project a GitHub user of their own, so that you can review and approve their pull requests."
---

By default, a bridge worker pushes and opens pull requests as the GitHub user
of the machine that runs the bridge. That is often you. GitHub does not let a
user approve their own pull request, so you cannot approve the work of your
agents. Give the agents a separate GitHub user, and their pull requests come
from that user.

Loupe does not create the account, and it never sees its token. The token stays
on the machine of the bridge. The bridge sends only the login to Loupe.

## Set up the account

The Agent GitHub account row of the
[readiness guide](../using/workshop.md#the-readiness-guide) links to a page
with these steps. Set up opens it. The project owner can also open it from
**Project settings**, then **Agent readiness**.

1. Create a separate GitHub user for the agents. Do not use your own account,
   or the account that installed the GitHub App.
2. Invite that user to the repository with write access. Sign in as that user
   and accept the invitation.
3. As that user, create a token. Use a fine-grained token with **Contents** and
   **Pull requests** set to read and write on the repository. A classic token
   with the `repo` scope also works.
4. On the machine of the bridge, run `loupe agent-account set`. Paste the token
   and press Enter. You can also pipe the token in. The command asks GitHub
   which user owns the token, and stores the token, the login and the user id.
   GitHub refuses a bad token, and the command then stores nothing.
5. Restart the bridge. The bridge reads the account when it starts.
6. On the agent account page in Loupe, record the login of the agent user and
   select **Save**.

Loupe refuses a login that is not a valid GitHub login. It also refuses the
login of the account that installed the GitHub App on the project, because the
agents need an account other than that one. An empty login clears the recorded
one.

## What the check reads

The Agent GitHub account row is done when both of these are true:

- The owner recorded a login on the agent account page.
- A running bridge of the project pushes as that login.

Loupe compares the two logins without regard to case. A quiet bridge does not
count. While the row is open, it says which part is missing: no login recorded
yet, or no running bridge that pushes as the recorded login yet.

## Commits and pushes

Each worker of the bridge pushes, commits and calls `gh` as the agent user. Git
records the author and the committer with the GitHub noreply address of that
user, `<id>+<login>@users.noreply.github.com`. For example, the user
`acme-agent` with the id `4242` commits as
`4242+acme-agent@users.noreply.github.com`.

## Remove the account

Run `loupe agent-account clear` on the machine of the bridge, then restart the
bridge. The workers then push as the user of the machine again. The bridge
sends an empty login, and Loupe clears the push login of that bridge. To clear
the login that the project records, save an empty login on the agent account
page.
