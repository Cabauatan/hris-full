# Build context is the backend repository root, not this directory.
FROM php:8.5-fpm-alpine AS runtime

RUN apk add --no-cache ca-certificates curl nginx oniguruma supervisor su-exec \
    && apk add --no-cache --virtual .extension-build-deps \
        $PHPIZE_DEPS curl-dev libxml2-dev oniguruma-dev \
    && extensions="$(php -r '$required = ["bcmath", "curl", "dom", "mbstring", "pcntl", "pdo_mysql", "xml", "xmlreader", "xmlwriter"]; echo implode(" ", array_filter($required, fn ($extension) => !extension_loaded($extension)));')" \
    && if [ -n "$extensions" ]; then docker-php-ext-install -j2 $extensions; fi \
    && apk del --no-network .extension-build-deps \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html

FROM runtime AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apk add --no-cache unzip
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
# Project Composer lifecycle scripts may generate keys or migrate databases.
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress \
        --no-scripts --no-autoloader

COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY artisan ./artisan
RUN composer dump-autoload --no-dev --optimize --no-interaction --no-scripts \
    && composer check-platform-reqs --no-dev

FROM runtime AS application
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr LOG_LEVEL=warning \
    PORT=10000 RUN_MIGRATIONS=false RUN_REVERB=true RUN_SCHEDULER=true \
    RUN_QUEUE_WORKER=true RUN_TELEGRAM_POLLER=false \
    HRIS_EMAIL_ENABLED=false TELEGRAM_ENABLED=false
COPY --from=dependencies /var/www/html /var/www/html
COPY resources/deployment/render/php.ini /usr/local/etc/php/conf.d/zz-hris.ini
COPY resources/deployment/render/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-hris.conf
COPY resources/deployment/render/nginx.conf /etc/nginx/nginx.conf
COPY resources/deployment/render/supervisord.conf /etc/supervisord.conf
COPY resources/deployment/render/entrypoint.sh /usr/local/bin/hris-entrypoint

RUN mkdir -p bootstrap/cache storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage \
    && chmod -R ug+rwX bootstrap/cache storage \
    && sed -i 's/\r$//' /usr/local/bin/hris-entrypoint \
    && chmod +x /usr/local/bin/hris-entrypoint

EXPOSE 10000
STOPSIGNAL SIGTERM
ENTRYPOINT ["hris-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
