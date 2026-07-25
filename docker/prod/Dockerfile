FROM php:8.5-fpm-alpine

WORKDIR /var/www/html

# Runtime libs stay; build-only headers/compilers go into a .build-deps virtual
# package that is removed after the extensions compile, so the final image ships
# no toolchain.
# pdo_pgsql: Postgres driver. intl: locale/translation. zip: composer/asset
# handling. xsl: XSLT support. bcmath: arbitrary-precision arithmetic.
# OPcache is already built into php:8.5-fpm-alpine — it just needs tuning (opcache.ini).
RUN apk add --no-cache postgresql-libs libzip libxslt icu-libs icu-data-full bash nginx supervisor \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev libzip-dev libxslt-dev icu-dev \
 && docker-php-ext-install bcmath pdo_pgsql zip intl xsl \
 && apk del .build-deps

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

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

# var/ is created and warmed above as root, but php-fpm workers run as www-data.
# Cache pools and prod logs are written lazily at runtime; without this, www-data
# cannot create those dirs and the writes (incl. any cache-backed rate limiter)
# silently fail. Hand all of var/ to www-data so runtime writes succeed.
RUN chown -R www-data:www-data var

EXPOSE 80

ENTRYPOINT ["docker/prod/entrypoint.sh"]
CMD ["/usr/bin/supervisord","-c","/etc/supervisord.conf"]
