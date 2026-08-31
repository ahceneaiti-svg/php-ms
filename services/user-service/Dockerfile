FROM dunglas/frankenphp:php8.3

RUN install-php-extensions \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    apcu \
    sockets

# git : requis par `composer install --prefer-source` ci-dessous
RUN apt-get update && apt-get install -y --no-install-recommends curl git \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
# --prefer-source (clone git) au lieu de --prefer-dist : l'endpoint GitHub
# codeload.github.com/<repo>/legacy.zip/<sha> renvoie actuellement HTTP 400
# pour les refs en commit-SHA (bug cote GitHub ; tar.gz et legacy.zip/<tag>
# fonctionnent). Composer demande le dist par SHA exact => echec du build.
# Repasser a --prefer-dist quand GitHub aura corrige.
RUN composer install --no-scripts --no-autoloader --prefer-source --no-interaction

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative

ENV SERVER_NAME=:80
ENV APP_ENV=prod

EXPOSE 80
