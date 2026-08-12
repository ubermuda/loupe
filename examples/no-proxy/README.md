# Example: no reverse proxy

Serves Loupe at `http://localhost:8080` — no Traefik, no certificate authority,
nothing to trust. Verified: brought up, `GET /healthz` returned 200 on loopback
and the same port was refused from the machine's LAN address.

`compose.yaml` here is an **override**, not a stack of its own. It publishes
nginx on a host port and redeclares the `traefik` network as project-local so
none has to exist.

## Running it

From the repository root, pass all three files:

```sh
docker compose -f compose.yaml -f compose.override.yaml \
  -f examples/no-proxy/compose.yaml up -d
```

Stop it the same way, with `down`. The order matters: the first `-f` sets the
project directory, so it must be the root `compose.yaml`.

Ports, if the defaults collide:

```sh
NOPROXY_PORT=9000 NOPROXY_MERCURE_PORT=9001 docker compose -f compose.yaml \
  -f compose.override.yaml -f examples/no-proxy/compose.yaml up -d
```

Both bind to `127.0.0.1`, because `app:dev:seed` creates an account whose
password is published in this repository. `NOPROXY_BIND=0.0.0.0` publishes them
to the network, if you mean to.

## Tell the app where it lives

Otherwise every generated link names port 80:

```dotenv
# .env.local
DEFAULT_URI=http://localhost:8080
```

For the Mercure hub, use **`.env.dev.local`**, not `.env.local` — `.env.dev`
pins `MERCURE_PUBLIC_URL` to the Traefik host and outranks `.env.local`, so a
value set there is read and then discarded. `bin/console debug:dotenv` prints
the precedence.

```dotenv
# .env.dev.local
MERCURE_PUBLIC_URL=http://localhost:8081/.well-known/mercure
```

The hub is opt-in; add `--profile mercure` and the `mercure` service to the
command above to start it.

## The catch: the site-review widget

The widget itself is scheme-agnostic — a `fetch` with a bearer token, no
browser API that requires a secure context. Embedding it in a page served over
**plain HTTP** works.

Embedding it in an **HTTPS** page is the doubtful case, and it is worth knowing
which rule bites. Mixed content is *not* the problem: `http://localhost` counts
as potentially trustworthy, so it is exempt. Chrome's private-network rules are
the likely blocker — a request from a public page to a local address wants a
preflight answered with `Access-Control-Allow-Private-Network`, and
`SiteReviewCorsSubscriber` does not send that header.

**This has not been tested**, and Chrome has changed the rule repeatedly. If you
review HTTPS pages, use [`../traefik-stepca/`](../traefik-stepca/) instead and
sidestep the question.
