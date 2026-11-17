# PHP image for running the panel plugin tests inside a Pelican or Pterodactyl checkout (extensions as in Pelican's Dockerfile.base, plus pdo_mysql and the mariadb client that loads Pterodactyl's schema dump).
FROM php:8.4-cli
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath gd intl zip pcntl pdo_mysql \
    && apt-get update && apt-get install -y --no-install-recommends git unzip mariadb-client && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
