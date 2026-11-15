# PHP 8.5 CLI with the extensions ComplyRobin needs (Postgres, intl, gd, zip, bcmath, exif, pcntl).
# Matches the Laravel Cloud PHP 8.5 runtime closely enough for local development and CI parity.
FROM php:8.5-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libicu-dev libpq-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) pdo_pgsql pgsql gd zip intl bcmath exif pcntl \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN echo "memory_limit=1G" > /usr/local/etc/php/conf.d/mem.ini
WORKDIR /app
