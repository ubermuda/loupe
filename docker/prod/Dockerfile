# The site-review widget is a third-party embed pulled in on every page of every
# customer site, so its size is paid by their visitors. The readable source stays
# in the repo and is what dev serves; only the image carries the compressed file,
# written to the same URL so embeds already on customer pages keep working.
FROM node:22-alpine AS widget
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts=false --no-audit --no-fund
COPY public/site-review/widget.js public/site-review/widget.js
RUN npm run build:widget

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
#
# `base` holds what both the build and the runtime need. The two stages share it
# rather than each installing PHP extensions, so the extensions the image runs
# are the same binaries the build ran.
FROM php:8.5-fpm-alpine@sha256:79def1d16ece3ab1a6656c46a23bfd80ad33887fbd33626e7bd743cef54ef9c6 AS base

# Both stages use the same path. `cache:warmup` writes absolute paths into the
# warmed cache, so a build stage that worked elsewhere would produce a cache
# pointing at a directory the runtime does not have.
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
# not. See docker/compose/prod.yaml.
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

# Everything below this line runs at build time only. Nothing here reaches the
# final image except the files the runtime stage copies, so a tool installed
# here needs no uninstall and a large download needs no prune to stay out.
FROM base AS build

# No limit for a stage that serves no request. Warming the cache compiles every
# Twig template in one process, and PHP's 128 MB default is what the template set
# outgrew. A runtime ceiling is a different question from a build one, so this
# deliberately does not follow the image.
RUN printf 'memory_limit=-1\n' > /usr/local/etc/php/conf.d/zz-build.ini

COPY . .

COPY --from=widget /build/var/widget.min.js public/site-review/widget.js

RUN set -eux; \
	mkdir -p var/cache var/log var/share var/tailwind var/exports; \
	chmod +x bin/console;

# git is Composer's fallback when a dist download fails. Without it a single
# transient GitHub 5xx on one zipball fails the whole build, because the source
# retry dies with "git was not found in your PATH".
RUN apk add --no-cache git \
 && composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative
RUN bin/console importmap:install
# The bundle downloads this itself, through a plain HttpClient with no retry, so
# one 5xx from GitHub fails the whole image build. It skips the download when the
# binary is already at var/tailwind/<version>/<name>, so Docker fetches it here
# instead and the build opens no socket of its own.
#
# The checksum is what makes the bytes verifiable rather than merely present, and
# it is what lets BuildKit cache the URL. Both sums come from the release's own
# sha256sums.txt. They move with binary_version in
# config/packages/symfonycasts_tailwind.yaml, and a version bumped in one place
# alone fails here on a mismatch, which is the failure you want.
ARG TARGETARCH
ADD --checksum=sha256:d861210e7c772e3b8a8b302d51bd4c8e2ab2e6f5dcb7545b16da6e9676c99079 \
	https://github.com/tailwindlabs/tailwindcss/releases/download/v4.3.0/tailwindcss-linux-x64-musl \
	var/tailwind/v4.3.0/tailwindcss-linux-x64-musl
ADD --checksum=sha256:9c106e815ce7ea99a65f91c13be2c51f00388dd3c0127c7a13a38f76cd1e26aa \
	https://github.com/tailwindlabs/tailwindcss/releases/download/v4.3.0/tailwindcss-linux-arm64-musl \
	var/tailwind/v4.3.0/tailwindcss-linux-arm64-musl

# ADD arrives 0644 and the bundle execs it. Only the one this platform runs is
# kept, so the other architecture's 110 MB never reaches the Tailwind step.
RUN set -eux; \
	case "$TARGETARCH" in \
		amd64) keep=tailwindcss-linux-x64-musl ;; \
		arm64) keep=tailwindcss-linux-arm64-musl ;; \
		*) echo "no Tailwind binary pinned for TARGETARCH=$TARGETARCH" >&2; exit 1 ;; \
	esac; \
	find var/tailwind/v4.3.0 -mindepth 1 ! -name "$keep" -delete; \
	chmod +x "var/tailwind/v4.3.0/$keep";

# Only app.built.css is needed past build time. The runtime stage copies this
# stage's final tree rather than its layers, so this removal is real wherever it
# sits.
RUN bin/console tailwind:build \
	&& find var/tailwind -mindepth 1 -maxdepth 1 ! -name 'app.built.css' -exec rm -rf {} +
RUN bin/console asset-map:compile
RUN bin/console cache:warmup --env=prod

# Build identity for the About page, written as a file rather than an env var so
# that its ABSENCE is meaningful: an image this build did not produce has no
# file, and the app says so instead of printing a version nobody set. Last of
# the build steps, so bumping it does not invalidate composer or the assets.
ARG APP_VERSION=""
RUN if [ -n "$APP_VERSION" ]; then printf '%s' "$APP_VERSION" > var/build-version; fi

# php-fpm workers run as www-data, and cache pools and prod logs are written
# lazily at runtime; without this, www-data cannot create those dirs and the
# writes (incl. any cache-backed rate limiter) silently fail.
#
# Done here rather than after the copy: a chown in the runtime stage rewrites
# every file it touches into a new layer, which cost 32 MB. COPY --from carries
# ownership, so this costs nothing. Only var/ changes hands, so the code stays
# root-owned and a request cannot rewrite the application it is serving.
RUN chown -R www-data:www-data var

# The image that serves traffic. It carries no compiler, no git, no Tailwind
# binary and no raised memory limit, because none of those were installed here.
FROM base AS runtime

COPY docker/prod/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/prod/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/prod/supervisord.conf /etc/supervisord.conf

# The whole tree in one copy, rather than a list of the directories that matter.
# A list is the failure this boundary invites: miss `var/cache` or the compiled
# assets and the image builds, starts, and fails on its first request.
COPY --from=build /var/www/html /var/www/html

# Provenance for the published package: GitHub links a container to its
# repository through image.source, and an AGPL image should say so where a tool
# can read it. An ARG rather than a literal for the same reason APP_SOURCE_URL
# is one — a fork's image must not claim upstream as the source of code it is
# not running. Labels are inherited, so the demo image built FROM this one
# needs no copy of them.
ARG APP_SOURCE_URL=https://github.com/ubermuda/loupe
LABEL org.opencontainers.image.source=$APP_SOURCE_URL
LABEL org.opencontainers.image.licenses=AGPL-3.0-or-later

EXPOSE 80

ENTRYPOINT ["docker/prod/entrypoint.sh"]
CMD ["/usr/bin/supervisord","-c","/etc/supervisord.conf"]
