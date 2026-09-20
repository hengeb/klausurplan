# Test-Image: PHP wie im Betrieb (8.5) mit MySQL-Treiber und pcov (Code-Coverage)
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli

RUN docker-php-ext-install pdo_mysql \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && echo "pcov.directory=/app/src" > /usr/local/etc/php/conf.d/pcov-src.ini

WORKDIR /app
