ARG PHP_VERSION=8.4
ARG PHP_DEBIAN_SUITE=bookworm

FROM composer:2 AS vendor-production
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress \
    --optimize-autoloader \
    --ignore-platform-req=ext-ldap --ignore-platform-req=ext-pdo_pgsql

FROM composer:2 AS vendor-test
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    --ignore-platform-req=ext-ldap --ignore-platform-req=ext-pdo_pgsql

FROM php:${PHP_VERSION}-fpm-${PHP_DEBIAN_SUITE} AS php-base
ARG PHP_DEBIAN_SUITE
RUN if [ "$PHP_DEBIAN_SUITE" = bullseye ]; then \
      sed -i 's|deb.debian.org|archive.debian.org|g' /etc/apt/sources.list; \
      echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive; \
    fi

FROM php-base AS extension-builder
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libldap2-dev \
    && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu \
    && docker-php-ext-install pdo_pgsql ldap \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/*

FROM php-base AS runtime-base
ARG PHP_DEBIAN_SUITE
RUN apt-get update \
    && apt-get upgrade -y --no-install-recommends \
    && if [ "$PHP_DEBIAN_SUITE" = bullseye ]; then LDAP_LIBRARY=libldap-2.4-2; else LDAP_LIBRARY=libldap-2.5-0; fi \
    && apt-get install -y --no-install-recommends libpq5 "$LDAP_LIBRARY" openssl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=extension-builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extension-builder /usr/local/etc/php/conf.d/docker-php-ext-ldap.ini /usr/local/etc/php/conf.d/
COPY --from=extension-builder /usr/local/etc/php/conf.d/docker-php-ext-pdo_pgsql.ini /usr/local/etc/php/conf.d/
WORKDIR /app

FROM runtime-base AS production
COPY --chown=www-data:www-data assets ./assets
COPY --chown=www-data:www-data commands ./commands
COPY --chown=www-data:www-data components ./components
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data controllers ./controllers
COPY --chown=www-data:www-data migrations ./migrations
COPY --chown=www-data:www-data models ./models
COPY --chown=www-data:www-data services ./services
COPY --chown=www-data:www-data views ./views
COPY --chown=www-data:www-data web ./web
COPY --chown=www-data:www-data yii requirements.php ./
COPY --from=vendor-production --chown=www-data:www-data /app/vendor ./vendor
RUN mkdir -p runtime web/assets \
    && chown -R www-data:www-data runtime web/assets \
    && chmod 0750 runtime web/assets
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM runtime-base AS test
COPY --from=extension-builder /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini /usr/local/etc/php/conf.d/
COPY --from=vendor-test /usr/bin/composer /usr/bin/composer
COPY --chown=www-data:www-data . .
COPY --from=vendor-test --chown=www-data:www-data /app/vendor ./vendor
RUN composer check-platform-reqs \
    && mkdir -p runtime web/assets \
    && chown -R www-data:www-data runtime web/assets
USER www-data
CMD ["php", "-v"]
