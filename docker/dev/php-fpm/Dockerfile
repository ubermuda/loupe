FROM php:8.5-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache $PHPIZE_DEPS postgresql-dev libzip-dev libxslt-dev bash icu-data-full linux-headers git

RUN docker-php-ext-install bcmath pdo pdo_pgsql zip intl xsl

# Installed from the tarball URL, not the package name. A package name makes
# pecl query the pecl.php.net REST index, which answers datacentre addresses
# with "No releases available" while it answers a laptop normally. A URL is
# also still pinned.
RUN pecl install https://xdebug.org/files/xdebug-3.5.3.tgz && docker-php-ext-enable xdebug

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY docker/dev/php-fpm/zz-pm.conf /usr/local/etc/php-fpm.d/zz-pm.conf
COPY docker/dev/php-fpm/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/dev/php-fpm/memory.ini /usr/local/etc/php/conf.d/zz-memory.ini
