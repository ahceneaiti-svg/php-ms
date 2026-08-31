FROM dunglas/frankenphp:php8.3

RUN install-php-extensions \
    intl \
    zip \
    opcache \
    apcu \
    pcntl \
    sockets

ENV DEBIAN_FRONTEND=noninteractive
# git : requis par `composer install --prefer-source` ci-dessous
RUN apt-get update && apt-get install -y --no-install-recommends curl git supervisor \
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

COPY docker/supervisord.conf /etc/supervisord.conf

ENV SERVER_NAME=:80
ENV APP_ENV=prod

EXPOSE 80

# Ce service tourne 2 process dans le meme conteneur (serveur HTTP pour
# /health + /metrics, et le consumer RabbitMQ) via supervisord — voir
# docker/supervisord.conf. Ce sont 2 processus PHP distincts : APCu n'est
# PAS partage entre eux (mkstemp() empeche le partage via un fichier mmap
# fixe, malgre ce qu'on peut lire ailleurs). Consequence : le consumer
# n'alimente pas le registre Prometheus expose par /metrics, seulement les
# logs (Loki) et les traces (Tempo) — voir ConsumeUserEventsCommand.
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]
