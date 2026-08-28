# Scheduled database dumps for the reference single-host stack, behind the
# `backup` compose profile. Built from the same base as the `database` service
# because pg_dump refuses to dump a server newer than itself, so the two stay in
# lockstep through one POSTGRES_VERSION.
ARG POSTGRES_VERSION=16
FROM postgres:${POSTGRES_VERSION}-alpine

# One static binary that speaks every S3-compatible store, configured entirely
# from the environment — no config file, and no credential squeezed through a
# URL where a `/` in a secret would corrupt it.
RUN apk add --no-cache rclone

# NO_CHECK_BUCKET: without it rclone probes, and would create, the bucket before
# every upload — which a key scoped to one bucket is not allowed to do.
ENV RCLONE_CONFIG=/dev/null \
    RCLONE_CONFIG_S3_TYPE=s3 \
    RCLONE_RETRIES=3 \
    RCLONE_CONTIMEOUT=1m \
    RCLONE_TIMEOUT=5m \
    RCLONE_S3_NO_CHECK_BUCKET=true

# Docker seeds an empty named volume from the image's own directory, ownership
# included, so the dump target is writable without running as root.
RUN mkdir -p /scratch && chown postgres:postgres /scratch

COPY --chown=root:root --chmod=0755 backup.sh /usr/local/bin/backup.sh

USER postgres
WORKDIR /scratch

# The base image's entrypoint initialises a data directory; this container is a
# client, not a server.
ENTRYPOINT ["/usr/local/bin/backup.sh"]
