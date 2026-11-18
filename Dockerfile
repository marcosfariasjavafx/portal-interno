FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && php -r 'exit(in_array("argon2id", password_algos(), true) ? 0 : 1);' \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/portal
COPY docker/php.ini /usr/local/etc/php/conf.d/portal.ini
COPY app ./app
COPY bin ./bin
COPY public ./public
