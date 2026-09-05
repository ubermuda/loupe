FROM php:8.5-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache $PHPIZE_DEPS postgresql-dev libzip-dev libxslt-dev bash icu-data-full linux-headers git

RUN docker-php-ext-install bcmath pdo pdo_pgsql zip intl xsl

# Installed from the xdebug.org tarball, so the build never contacts pecl.php.net.
# A package name makes pecl query that REST index, which fails intermittently: on
# one commit three image builds succeeded and a fourth got "No releases available"
# minutes apart. A URL skips the lookup rather than improving the odds, pins the
# version, and makes xdebug.org the host that must be reachable instead.
RUN pecl install https://xdebug.org/files/xdebug-3.5.3.tgz && docker-php-ext-enable xdebug

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY docker/dev/php-fpm/zz-pm.conf /usr/local/etc/php-fpm.d/zz-pm.conf
COPY docker/dev/php-fpm/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/dev/php-fpm/memory.ini /usr/local/etc/php/conf.d/zz-memory.ini
