# Example: Traefik + step-ca for `*.dev.localhost`

A working local reverse proxy that gives every `*.dev.localhost` hostname a
**trusted** HTTPS certificate, which is what Loupe's compose labels expect. Copy
this directory anywhere outside the repo and run it — it is a standalone stack,
not part of Loupe, and one instance serves every project on the machine.

It is an example rather than a supported component: it mirrors the setup Loupe
is developed against, and you are meant to adapt it.

## What it is

| Container | Why |
|---|---|
| **traefik** | The proxy. Terminates TLS on `:443`, routes `:5432` to Postgres, requests certificates over ACME. |
| **step-ca** | A local ACME certificate authority. Issues per-hostname certs on first request, renewed automatically. |
| **dnsmasq** | Answers `*.dev.localhost` at any depth, so step-ca can reach a hostname to validate its challenge. Docker's own DNS has no wildcards, and `mailpit.loupe.dev.localhost` is two levels deep. |

The extra `dev` level is deliberate: Chrome rejects wildcard certificates for
single-label domains, so `myapp.localhost` cannot work and `myapp.dev.localhost`
can.

## Setup

```sh
just up      # creates the network and CA password, starts all three
just trust   # installs the root CA — macOS, needs sudo, restart Chrome after
```

`just trust` is the one manual step, and it is unavoidable: a local CA's root is
generated on your machine and no browser trusts it until you say so. On Linux,
copy `step-ca/certs/root_ca.crt` into your distribution's trust store instead.

Then, from a Loupe clone, `just up` — it joins the same `traefik` network and
appears at `https://loupe.dev.localhost`. The dashboard is at
`https://traefik.dev.localhost`.

## If you already run a Traefik

Don't run this one as well. The `traefik` network is shared across projects, and
Compose registers every service name as a network alias on it — so a second
container named `step-ca` makes Docker DNS round-robin between two CAs. ACME
then intermittently trusts the wrong root and certificate issuance fails for
**every** site on the machine with `x509: certificate signed by unknown
authority`. Point your existing proxy at Loupe instead, using the labels in
Loupe's `compose.yaml`.

## Files

- `compose.yml` — the three services, on fixed addresses in `172.20.0.0/24`.
- `config/dnsmasq.conf` — the wildcard answer, one line.
- `config/dashboard.yml` — routes the Traefik dashboard.
- `justfile` — `up`, `down`, `logs`, `trust`, `network`.

`step-ca/` and `certs/` are generated on first run and gitignored: they hold the
CA's private keys and the issued certificates. Never commit them.
