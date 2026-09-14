FROM php:8.5-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache $PHPIZE_DEPS postgresql-dev libzip-dev libxslt-dev bash icu-data-full linux-headers git

RUN docker-php-ext-install -j"$(nproc)" bcmath pdo pdo_pgsql zip intl xsl

# Built from the xdebug.org tarball without pecl, so the build never contacts
# pecl.php.net. `pecl install <url>` still fetches that host's channel.xml, and a
# 504 there failed a CI image build on 2026-09-12.
RUN mkdir -p /usr/src/xdebug \
    && curl -fsSL https://xdebug.org/files/xdebug-3.5.3.tgz | tar -xz -C /usr/src/xdebug --strip-components=1 \
    && docker-php-ext-install -j"$(nproc)" /usr/src/xdebug

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY docker/dev/php-fpm/zz-pm.conf /usr/local/etc/php-fpm.d/zz-pm.conf
COPY docker/dev/php-fpm/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/dev/php-fpm/memory.ini /usr/local/etc/php/conf.d/zz-memory.ini
