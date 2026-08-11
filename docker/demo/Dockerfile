# A whole Loupe instance in one container, for evaluating the app. It adds
# Postgres and a messenger worker to the production image so that
# `docker run -p 8080:80` is the entire setup, with no compose file, no
# database to provision and no reverse proxy.
#
# NOT a deployment target. APP_SECRET is a committed constant, the database
# lives in the container's writable layer, and no TLS terminates in front. Use
# `compose.prod.yaml` to actually run this software.
#
# Built FROM the production image rather than repeating its build steps, so the
# application layer here is byte-identical to the one that ships. The reference
# below is never pulled: docker-bake.hcl serves it from a `prod` target built in
# the same pass, which is what lets this image be multi-platform without a
# multi-platform production image being published. Build it with
# `just build-demo`, not a bare `docker build`.
ARG LOUPE_BASE=ghcr.io/ubermuda/loupe:prod
FROM ${LOUPE_BASE}

# 16 to match compose.prod.yaml, and therefore the serverVersion below.
RUN apk add --no-cache postgresql16 postgresql16-client

COPY docker/demo/supervisord.conf /etc/supervisord-demo.conf
COPY docker/demo/entrypoint.sh /usr/local/bin/demo-entrypoint.sh
RUN chmod +x /usr/local/bin/demo-entrypoint.sh

ENV PGDATA=/var/lib/postgresql/data

# Every value a self-hosted instance would set, answered for a throwaway one.
# The entrypoint runs `composer dump-env prod` at boot rather than at build, so
# `docker run -e DEFAULT_URI=…` still overrides any of these.
ENV APP_ENV=prod \
    APP_SECRET=000000000000000000000000000demo \
    DATABASE_URL="postgresql://loupe:loupe@127.0.0.1:5432/loupe?serverVersion=16&charset=utf8" \
    DEFAULT_URI=http://localhost:8080 \
    MAILER_DSN=null://null \
    MAILER_FROM_ADDRESS=loupe@example.com \
    MAILER_FROM_NAME=Loupe \
    MCP_ALLOWED_HOSTS=localhost,127.0.0.1,[::1] \
    DEMO_ADMIN_EMAIL=admin@example.com \
    DEMO_ADMIN_PASSWORD=loupe-admin

# No VOLUME on PGDATA on purpose: without one the database is ephemeral, which
# is what evaluating the app wants, and `-v loupe-demo:/var/lib/postgresql/data`
# still opts into keeping it.
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/demo-entrypoint.sh"]
CMD ["/usr/bin/supervisord","-c","/etc/supervisord-demo.conf"]
