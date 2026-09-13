FROM php:7.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libldap2-dev \
    && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu \
    && docker-php-ext-install pdo_pgsql ldap \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-progress

COPY . .

EXPOSE 8080

CMD ["sh", "-lc", "php yii migrate --interactive=0 && php -S 0.0.0.0:8080 -t web web/router.php"]
