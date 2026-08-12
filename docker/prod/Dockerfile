# Pinned by digest so the same Dockerfile always produces the same base layer:
# `php:8.5-fpm-alpine` is a mutable tag that is rebuilt on every patch release
# and every Alpine bump, so without this two builds a week apart ship different
# PHP binaries and different system libraries. The tag is kept alongside the
# digest purely for readability — Docker resolves the digest, not the tag.
#
# This is a multi-platform manifest-list digest, so it resolves on arm64 (a
# local `just shell-prod`) as well as on the amd64 `just build-prod` target.
#
# To move to a newer base:
#   docker buildx imagetools inspect php:8.5-fpm-alpine --format '{{.Manifest.Digest}}'
FROM php:8.5-fpm-alpine@sha256:79def1d16ece3ab1a6656c46a23bfd80ad33887fbd33626e7bd743cef54ef9c6

WORKDIR /var/www/html

# Runtime libs stay; build-only headers/compilers go into a .build-deps virtual
# package that is removed after the extensions compile, so the final image ships
# no toolchain.
# pdo_pgsql: Postgres driver. intl: locale/translation. zip: composer/asset
# handling. xsl: XSLT support. bcmath: arbitrary-precision arithmetic.
# OPcache is already built into php:8.5-fpm-alpine — it just needs tuning (opcache.ini).
#
# su-exec is how a worker container drops to www-data *after* the entrypoint has
# run as root (it needs to write .env.local.php, which the project directory only
# lets root create). It matters when web and worker share an export volume: the
# local Flysystem adapter writes archives 0600, so an archive written by a root
# worker is unreadable to the web container's www-data php-fpm workers. su-exec
# replaces itself with the command rather than forking, so the consumer keeps
# PID 1 and still receives SIGTERM for a graceful shutdown — busybox `su` does
# not. See deploy/compose.prod.yaml.
RUN apk add --no-cache postgresql-libs libzip libxslt icu-libs icu-data-full bash nginx supervisor su-exec \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev libzip-dev libxslt-dev icu-dev \
 && docker-php-ext-install bcmath pdo_pgsql zip intl xsl \
 && apk del .build-deps

# Pinned and copied rather than curl-piped from getcomposer.org: the installer
# was unversioned and its checksum unverified, so two builds a week apart shipped
# different Composer versions under a Dockerfile that advertises reproducibility.
# Composer stays in the final image because entrypoint.sh runs `dump-env prod`.
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

ENV APP_ENV=prod

COPY docker/prod/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/prod/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/prod/supervisord.conf /etc/supervisord.conf

COPY . .

RUN set -eux; \
	mkdir -p var/cache var/log var/share var/tailwind var/exports; \
	chmod +x bin/console;

RUN composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative
RUN bin/console importmap:install
RUN bin/console tailwind:build
RUN bin/console asset-map:compile
RUN bin/console cache:warmup --env=prod

# Build identity for the About page, written as a file rather than an env var so
# that its ABSENCE is meaningful: an image this build did not produce has no
# file, and the app says so instead of printing a version nobody set. Last of
# the build steps, so bumping it does not invalidate composer or the assets.
ARG APP_VERSION=""
RUN if [ -n "$APP_VERSION" ]; then printf '%s' "$APP_VERSION" > var/build-version; fi

# var/ is created and warmed above as root, but php-fpm workers run as www-data.
# Cache pools and prod logs are written lazily at runtime; without this, www-data
# cannot create those dirs and the writes (incl. any cache-backed rate limiter)
# silently fail. Hand all of var/ to www-data so runtime writes succeed.
RUN chown -R www-data:www-data var

EXPOSE 80

ENTRYPOINT ["docker/prod/entrypoint.sh"]
CMD ["/usr/bin/supervisord","-c","/etc/supervisord.conf"]
