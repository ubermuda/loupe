FROM php:8.5-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache $PHPIZE_DEPS postgresql-dev libzip-dev libxslt-dev bash icu-data-full linux-headers git

RUN docker-php-ext-install bcmath pdo pdo_pgsql zip intl xsl

RUN pecl install xdebug && docker-php-ext-enable xdebug

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY docker/dev/php-fpm/zz-pm.conf /usr/local/etc/php-fpm.d/zz-pm.conf
COPY docker/dev/php-fpm/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/dev/php-fpm/memory.ini /usr/local/etc/php/conf.d/zz-memory.ini
