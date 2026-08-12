---
title: Getting started
description: Five ways to run Loupe, and what separates them — who your first account is.
---

Loupe never leaves a fresh instance open to whoever finds it: registration
refuses to create the **first** account. So every path below has to say where
that first account comes from, and that is what actually distinguishes them.

| I want to… | Page | First account |
|---|---|---|
| Look at it, without cloning | [Demo](demo.md) | `admin@example.com` / `loupe-admin`, baked into the image |
| Develop on it | [From source](from-source.md) | `dev@loupe.test` / `password`, from `app:dev:seed` |
| Run it on one machine | [Single-host Docker Compose](docker-compose.md) | the wizard at `/install`, gated by `INSTALL_TOKEN` |
| Run it on DigitalOcean | [App Platform](digitalocean.md) | the same wizard |
| Get back in when locked out | [Recovering an instance](../operating/recovering.md) | the address you name; creates or promotes it |

`app:admin:create` is the escape hatch for every row, not just the last. It
works on any instance you have a shell on, and needs no mail, no token and no
wizard.

## Before a real deploy

Read **[What runs in production](architecture.md)** — Loupe is two processes
from one image, and the second one is not optional. Then read
**[Known gaps](../known-gaps.md)**: several things the application needs are not
configured on your behalf, and two of them leave a feature broken rather than
merely off.

`INSTALL_TOKEN` is the one to set *before* the first deploy rather than after.
In production the install wizard fails closed — with no token configured,
`/install` returns 404 and the browser has no route to the first administrator.
See [First run](../operating/first-run.md).
