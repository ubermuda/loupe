---
title: "Connected apps"
description: "How an app connects to a Loupe account through OAuth, and how a user removes its access."
---

Loupe is an OAuth 2.1 authorization server. An app can ask a user for access,
and the user approves it on a consent page. The app then gets a short-lived
access token and a refresh token. Nobody copies a token by hand.

Static API tokens still work. Use them for CI and for scripts. See
[MCP](mcp.md) for the token page.

## The consent page

An app sends the user to `/oauth/authorize`. The user signs in first when
necessary. The consent page then shows three facts:

- The name of the app.
- The access the app asks for.
- The address that receives the answer.

When every address of the app is on the user's own computer, such as
`localhost` or `127.0.0.1`, the page shows a warning. Loupe cannot check which
app listens there, so approve only a connection that you started yourself.

An app asks for exactly one kind of access:

| Scope | Access | Project |
|---|---|---|
| `mcp` | The documents and site reviews of one project, through MCP. | The user picks one project that they own. |
| `site-review` | The site-review comments of one project. | The user picks one project that they own. |
| `agent` | The agent surface that the bridge uses. | None. |

The user can pick only a project that they own. An app cannot choose the
project itself.

## Connected apps in account settings

The *Connected apps* section of the account settings, at
`/account/connected-apps`, lists each app that holds access. Each row shows the
access and the project. **Revoke** removes the access of that app at once: its
access tokens and its refresh tokens stop working. To connect again, the app
must ask the user again.

Loupe also removes access in these cases:

- The user deletes the project. Every grant for that project stops working.
- The user deletes the account. Loupe deletes every token of the account.
- An administrator suspends the user. The tokens stop working, and a refresh
  fails.

The data export includes `connected_apps.json`. It lists each connected app and
its access. It contains no token.

## For app developers

The discovery document is at `/.well-known/oauth-authorization-server`
(RFC 8414). The server supports these features:

- The authorization code grant with PKCE. Only the `S256` method is accepted,
  and a public client must send a code challenge.
- The refresh token grant. Each refresh returns a new refresh token, and the
  old one stops working.
- The `iss` parameter on every redirect back to the app (RFC 9207).

Access tokens are JWTs that live for one hour. Send them in an
`Authorization: Bearer` header to `/mcp` or `/api`.

An operator registers each app with `league:oauth2-server:create-client`. See
[Commands](../reference/commands.md). Loupe has no dynamic client
registration.
