FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

FROM node:20-bookworm-slim AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.ts tsconfig.json tailwind.config.js components.json ./
COPY --from=vendor /app/vendor ./vendor

RUN npm run build

FROM php:8.2-cli-bookworm

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    curl \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    libicu-dev \
    libsqlite3-dev \
    bash \
    && docker-php-ext-install \
    bcmath \
    intl \
    pcntl \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build

RUN rm -f bootstrap/cache/*.php && php artisan package:discover --ansi
RUN chown -R www-data:www-data storage bootstrap/cache
RUN chmod +x docker/entrypoint.sh

ENV APP_ENV=production
ENV LOG_CHANNEL=stderr
ENV RUN_MIGRATIONS=true
ENV RUN_FRESH_SEED_ON_FIRST_BOOT=true
ENV RUN_SCHEDULER=true
ENV DB_MAX_ATTEMPTS=30
ENV DB_RETRY_SECONDS=3

EXPOSE 10000

ENTRYPOINT ["bash", "docker/entrypoint.sh"]
