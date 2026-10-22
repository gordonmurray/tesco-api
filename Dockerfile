# PHP 8.5.12 includes the 2026-10-21 critical security fixes.
ARG PHP_VERSION=8.5.12
FROM php:${PHP_VERSION}-apache-bookworm AS base
WORKDIR /app
RUN php -r 'if (PHP_VERSION_ID < 80512) { exit(1); }' \
    && a2dissite 000-default \
    && printf 'Listen 8080\n' > /etc/apache2/ports.conf \
    && mkdir -p /app/var \
    && chown www-data:www-data /app/var
COPY docker/apache.conf /etc/apache2/sites-enabled/app.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
ENV APACHE_RUN_DIR=/tmp/apache2 APACHE_LOCK_DIR=/tmp/apache2 APACHE_PID_FILE=/tmp/apache2/apache2.pid
EXPOSE 8080

FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
COPY src ./src
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && composer check-platform-reqs --no-dev

FROM build AS test
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts
COPY tests ./tests
COPY phpunit.xml ./
COPY public ./public
COPY bin ./bin
RUN composer dump-autoload
CMD ["vendor/bin/phpunit"]

FROM base AS runtime
COPY --from=build /app/vendor ./vendor
COPY src ./src
COPY public ./public
COPY bin ./bin
COPY examples ./examples
USER www-data
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/health") === "{\"status\":\"ok\"}" ? 0 : 1);'
CMD ["apache2-foreground"]
