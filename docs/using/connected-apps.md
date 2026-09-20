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

An app can identify itself with a URL, such as Claude Code with
`https://claude.ai/oauth/claude-code-client-metadata`. The consent page then
shows the domain of that URL as the app, because Loupe checked it. The name the
app gives itself shows below it, marked as not verified.

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

## Signing in the CLI with a code

`loupe login` with no token uses the device flow (RFC 8628), because the CLI has
no browser of its own. It prints a link to `/oauth/device` and a code of eight
letters, such as `BCDF-GHJK`. Open the link in a browser where you are signed in
to Loupe. The page shows the app, the access and the code. Choose **Allow** only
if you started `loupe login` yourself and the code is the code in your
terminal. The CLI then gets its tokens and stores them.

The page also takes a code that you type at `/oauth/device`. Case, spaces and
dashes do not matter. A code expires after ten minutes, and it takes one answer
only. Each account can try 20 codes in 15 minutes.

Every instance registers the CLI as the public client `loupe-cli`, with the
`agent` scope only. A migration adds it, and it leaves an existing `loupe-cli`
row as it is. The CLI appears on the *Connected apps* page as *Loupe CLI*.

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
- The device authorization grant (RFC 8628), at `/oauth/device-authorization`.
  Only a client that lists this grant can start a device flow, and it can ask
  only for a scope that needs no project.
- The refresh token grant. Each refresh returns a new refresh token, and the
  old one stops working.
- The `iss` parameter on every redirect back to the app (RFC 9207).

Access tokens are JWTs that live for one hour. Send them in an
`Authorization: Bearer` header to `/mcp` or `/api`.

An operator registers each app with `league:oauth2-server:create-client`. See
[Commands](../reference/commands.md). Loupe has no dynamic client
registration.

An MCP client can skip the registration with a Client ID Metadata Document:

- The `client_id` is the https URL of a JSON document. The URL has a path, and
  no port, query, fragment or user name. Its host is a DNS name.
- The document's `client_id` equals that URL, and `token_endpoint_auth_method`
  is `none`. It lists from one to ten `redirect_uris`. Each is https, or http
  on `localhost`, `127.0.0.1` or `[::1]`.
- Loupe fetches the document when a user starts to connect the app. The
  document must be at most 5 KB of `application/json`, with no redirect. Loupe
  keeps it for its `Cache-Control` max-age, from five minutes to one day.
- Such an app can ask for the `mcp` scope only.

The `mcp` scope binds the token to the MCP endpoint (RFC 8707). Send
`resource=https://<host>/mcp` to `/oauth/authorize` and `/oauth/token`, or
leave it out. Any other value gets `invalid_target`. The token's `aud` claim is
that URI, and `/mcp` refuses a token for another audience. The protected
resource metadata is at `/.well-known/oauth-protected-resource/mcp` (RFC 9728).

An http redirect address on `localhost`, `127.0.0.1` or `[::1]` matches on any
port (RFC 8252). Every other address must match exactly.
