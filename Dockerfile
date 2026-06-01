# syntax=docker/dockerfile:1

FROM node:22-alpine@sha256:968df39aedcea65eeb078fb336ed7191baf48f972b4479711397108be0966920 AS assets

WORKDIR /app

COPY package.json package-lock.json ./
COPY resources/assets/v1/package.json ./resources/assets/v1/package.json
COPY resources/assets/v2/package.json ./resources/assets/v2/package.json
COPY patches ./patches
RUN npm ci

COPY resources ./resources
COPY public ./public
RUN npm --workspace resources/assets/v1 run production \
    && npm --workspace resources/assets/v2 run build

FROM php:8.5-cli-alpine@sha256:6ca76906d789edfac74e5f109c800b71e571bd313277133eaddc079733ee0b65 AS vendor

WORKDIR /app

RUN apk add --no-cache \
        curl-dev \
        icu-dev \
        libxml2-dev \
        linux-headers \
        oniguruma-dev \
        postgresql-dev \
        sqlite-dev \
        unzip \
    && docker-php-ext-install \
        bcmath \
        curl \
        intl \
        mbstring \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        simplexml \
        xml \
        xmlwriter

COPY --from=composer:2@sha256:1b73755de4f19775ba6087fd5313664493e06fab72b6fc27dc2044e87bb7c4c3 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --optimize-autoloader

FROM php:8.5-cli-alpine@sha256:6ca76906d789edfac74e5f109c800b71e571bd313277133eaddc079733ee0b65

ARG BUILD_DATE=unknown
ARG VCS_REF=unknown
ARG IMAGE_SOURCE=https://github.com/Guisardo/firefly-iii
ARG IMAGE_TITLE="Firefly III shared administration fork"

LABEL org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.description="Firefly III fork with shared administration access work packaged from source." \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.revision="${VCS_REF}" \
      org.opencontainers.image.source="${IMAGE_SOURCE}" \
      org.opencontainers.image.title="${IMAGE_TITLE}" \
      org.opencontainers.image.vendor="Guisardo" \
      org.opencontainers.image.version="${VCS_REF}"

WORKDIR /var/www/html

RUN apk add --no-cache \
        curl-dev \
        icu-dev \
        libxml2-dev \
        linux-headers \
        oniguruma-dev \
        postgresql-dev \
        sqlite-dev \
    && docker-php-ext-install \
        bcmath \
        curl \
        intl \
        mbstring \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        simplexml \
        xml \
        xmlwriter \
    && addgroup -g 1000 firefly \
    && adduser -D -G firefly -u 1000 firefly

COPY --chown=firefly:firefly . .
COPY --from=vendor --chown=firefly:firefly /app/vendor ./vendor
COPY --from=assets --chown=firefly:firefly /app/public ./public

RUN mkdir -p \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R firefly:firefly storage bootstrap/cache

USER firefly

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r "exit(trim((string) @file_get_contents('http://127.0.0.1:8080/health')) === 'OK' ? 0 : 1);"

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
