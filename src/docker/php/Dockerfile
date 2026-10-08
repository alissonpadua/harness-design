FROM php:8.4-fpm-bookworm

# PECL tarballs vendored in docker/php/deps (refresh via docker/php/fetch-deps.sh on host)
COPY docker/php/deps/ /tmp/deps/

# Corporate TLS-interception root (see docker/php/certs/README.md)
COPY docker/php/certs/ /usr/local/share/ca-certificates/

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libicu-dev libzip-dev libonig-dev ca-certificates \
        libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && update-ca-certificates \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql bcmath intl zip pcntl opcache mbstring gd \
    && pecl install /tmp/deps/redis-6.2.0.tgz /tmp/deps/xdebug-3.5.0.tgz \
    && docker-php-ext-enable redis xdebug \
    && rm -rf /tmp/deps \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY docker/php/php.ini.dev /usr/local/etc/php/conf.d/99-dev.ini

ENV PHP_IDE_CONFIG=serverName=boilerplate
