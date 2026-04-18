# syntax=docker/dockerfile:1

FROM node:22-bookworm-slim AS assets
WORKDIR /app

COPY package.json ./
COPY vite.config.js ./
COPY resources ./resources

RUN npm install --no-audit --no-fund
RUN npm run build

FROM php:8.2-apache AS app

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libpq-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) gd pdo pdo_pgsql zip \
  && a2enmod rewrite \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=assets /app/public/build /var/www/html/public/build

RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-scripts --no-interaction --no-progress \
  && mkdir -p storage/framework/{sessions,views,cache,testing} storage/logs bootstrap/cache \
  && chown -R www-data:www-data storage bootstrap/cache

RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
  && sed -ri -e 's!/var/www/!/var/www/html/public!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

EXPOSE 80

RUN chmod +x /var/www/html/docker-entrypoint.sh

CMD ["/var/www/html/docker-entrypoint.sh"]
