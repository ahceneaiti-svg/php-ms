FROM dunglas/frankenphp:php8.3

RUN install-php-extensions \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    apcu

RUN apt-get update && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative

ENV SERVER_NAME=:80
ENV APP_ENV=prod

EXPOSE 80
