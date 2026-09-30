FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-install zip mbstring xml \
    && rm -rf /var/lib/apt/lists/* \
    && php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');" \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php

WORKDIR /app
COPY . .
RUN mkdir -p bootstrap/cache storage/app/data storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer install --no-interaction --prefer-dist --optimize-autoloader \
    && cp .env.example .env \
    && php artisan key:generate --force \
    && mkdir -p storage/app/data storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && php artisan rally:setup

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
