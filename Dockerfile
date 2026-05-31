FROM node:22-alpine AS assets

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

FROM php:8.5-cli-alpine AS vendor

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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --optimize-autoloader

FROM php:8.5-cli-alpine

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

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
