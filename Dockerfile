# Production image for Dokploy. One image runs the web server, the queue worker
# and the scheduler; docker-compose.yml picks the role per service.

ARG PHP_IMAGE=serversideup/php:8.4-fpm-nginx-v5.2.1

############################################
# Base: PHP 8.4 + Nginx, plus the extensions the app needs
############################################
FROM ${PHP_IMAGE} AS base

USER root
RUN install-php-extensions intl bcmath
USER www-data

############################################
# Build: Composer dependencies and Vite assets
# Wayfinder generates route helpers by booting the app during `npm run build`,
# so this stage needs both PHP and Node.
############################################
FROM base AS build

USER root
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
USER www-data

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist

COPY --chown=www-data:www-data package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --no-dev --optimize \
    && npm run build

############################################
# Production
############################################
FROM base AS production

ENV PHP_OPCACHE_ENABLE=1 \
    HEALTHCHECK_PATH=/up

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --no-autoloader

COPY --chown=www-data:www-data . .
COPY --from=build --chown=www-data:www-data /var/www/html/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative
