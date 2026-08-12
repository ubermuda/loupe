---
title: Running migrations
description: The one-shot release step. Applies to every topology.
---


**Never run migrations from the container entrypoint.**
`docker/prod/entrypoint.sh` deliberately does not — with several replicas,
per-container migrations race against the same database.
`docker/prod/release.sh` is the one-shot release step, run once per deploy:

```bash
docker run --rm --env-file <your prod env file> <image> docker/prod/release.sh
```

The two topologies below each have their own way of invoking it.

