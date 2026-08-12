# Examples

Ways to serve Loupe locally. `just up` publishes no host ports and joins an
external Docker network named `traefik`, so it needs one of these — it *fails*
rather than degrades when neither is in place.

| Example | What you get | Runnable as-is |
|---|---|---|
| [`traefik-stepca/`](traefik-stepca/) | `https://loupe.dev.localhost` with certificates your browser trusts, plus every other `*.dev.localhost` project on the machine | Yes, on macOS |
| [`no-proxy/`](no-proxy/) | `http://localhost:8080`, no proxy, no certificate authority | Yes |

**These are examples, not products.** Read them, take what fits, change the
rest. Some are verified end to end and say so; others are reference
configuration meant to be adapted — each README states which it is, and a config
that has not been run is labelled rather than implied.

## Which one

Take **`traefik-stepca/`** if you want the setup this project is developed
against, or if you run several `*.dev.localhost` projects and want one proxy in
front of all of them. It costs one manual step: trusting a locally generated
root certificate.

Take **`no-proxy/`** to avoid that entirely. The cost is plain HTTP, which is
fine for the app itself and awkward for the site-review widget — see that
directory's README.

## Adding one

A new example is a directory with a README saying what it is, whether it has
been run, and what to change. Nothing here is wired into the justfile: examples
that need commands carry their own.
