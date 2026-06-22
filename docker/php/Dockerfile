### jtrader PHP-FPM image (Laravel 12 + Filament 4)
#
# Base: serversideup/php — a Laravel-focused, production-ready PHP image that
# already bundles the extensions we need (pdo_mysql, mbstring, redis, zip,
# opcache, openssl, tokenizer, xml, curl ...) plus Composer.
FROM serversideup/php:8.4-fpm

# Match the container user to the host UID/GID for clean bind-mount permissions.
ARG UID=1000
ARG GID=1000

USER root
RUN docker-php-serversideup-set-id www-data ${UID}:${GID}

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 \
        python3-matplotlib \
        libicu-dev \
    && docker-php-ext-install intl \
    && apt-get purge -y --auto-remove libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# Runtime tuning is done via env vars (see docker-compose.yml):
#   PHP_OPCACHE_ENABLE, PHP_MEMORY_LIMIT, PHP_MAX_EXECUTION_TIME, ...

WORKDIR /var/www/html

USER www-data
