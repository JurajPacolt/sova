# syntax=docker/dockerfile:1.7

FROM node:24.15.0-bookworm-slim AS frontend-build

# Staging and CI build through the full gate. The local stack in
# docker-compose.yml sets this to 0, so a formatting or unit test failure cannot
# stop the application from starting; the bundle is identical either way.
ARG FRONTEND_VERIFY=1

WORKDIR /build/frontend

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./
RUN if [ "$FRONTEND_VERIFY" = "1" ]; then npm run check; else npm run build; fi

FROM composer:2.9.7 AS composer-bin

FROM php:8.3.32-apache-bookworm AS php-base

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && apt-get update \
    && apt-get install --yes --no-install-recommends \
        ca-certificates \
        curl \
        libonig-dev \
        libonig5 \
        libpq-dev \
        libpq5 \
        libzip-dev \
        libzip4 \
    && docker-php-ext-install -j"$(nproc)" mbstring opcache pdo_pgsql zip \
    && a2enmod deflate expires headers remoteip rewrite \
    && apt-get purge --yes --auto-remove libonig-dev libpq-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/sova/backend

COPY backend/composer.json backend/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

COPY backend/ ./
RUN composer dump-autoload \
        --no-dev \
        --classmap-authoritative \
        --no-interaction \
    && mkdir -p /var/lib/sova/attachments \
    && chown -R www-data:www-data /var/lib/sova

ENV APP_ENV=production \
    APP_DEBUG=false \
    ATTACHMENT_STORAGE_PATH=/var/lib/sova/attachments \
    LOG_PATH=php://stderr

FROM php-base AS test

COPY docs/openapi.json /var/www/sova/docs/openapi.json

RUN composer install \
        --no-interaction \
        --no-progress \
        --prefer-dist

CMD ["composer", "check"]

FROM php-base AS worker

USER www-data

ENTRYPOINT ["docker-php-entrypoint"]
CMD ["php", "bin/outbox-worker.php"]

FROM php-base AS web

COPY --from=frontend-build /build/frontend/dist/sova-frontend/browser/ /var/www/sova/frontend/
COPY deploy/staging/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/staging/apache-hardening.conf /etc/apache2/conf-available/sova-hardening.conf
COPY deploy/staging/container-entrypoint.sh /usr/local/bin/sova-container-entrypoint

RUN a2enconf sova-hardening \
    && chmod 0555 /usr/local/bin/sova-container-entrypoint \
    && chown -R root:root /var/www/sova/frontend

WORKDIR /var/www/sova/backend

HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=12 \
    CMD curl --fail --silent --show-error http://127.0.0.1/api/v1/health/ready >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/sova-container-entrypoint"]
CMD ["apache2-foreground"]
