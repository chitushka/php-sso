FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader

FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libldap2-dev openssl \
    && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu \
    && docker-php-ext-install pdo_pgsql ldap \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .
COPY --from=vendor /app/vendor /app/vendor
RUN mkdir -p runtime/logs web/assets && chown -R www-data:www-data runtime web/assets

EXPOSE 9000

CMD ["php-fpm", "-F"]
