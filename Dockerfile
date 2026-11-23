# Production image for Render (runtime: Docker). Laravel 13 on php:8.3-apache.
# Stages: app + PHP deps (composer) -> front-end assets (node) -> runtime.

# --- 1. App + PHP dependencies ------------------------------------------------------------
FROM php:8.3-apache AS app

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo pdo_mysql pdo_pgsql pgsql zip mbstring gd bcmath opcache \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && a2enmod rewrite \
 && : > /etc/apache2/ports.conf

# Document root public/, AllowOverride All, Listen 0.0.0.0:${PORT}
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction

# --- 2. Front-end assets (package.json exists; Tailwind scans app/ + vendor views) -----------
FROM node:20-alpine AS assets
WORKDIR /app
COPY --from=app /var/www/html /app
RUN npm ci && npm run build

# --- 3. Runtime ---------------------------------------------------------------------------
FROM app
COPY --from=assets /app/public/build ./public/build
COPY docker/start.sh /usr/local/bin/start

RUN sed -i 's/\r$//' /usr/local/bin/start && chmod +x /usr/local/bin/start \
 && mkdir -p storage/logs storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

# Production defaults; anything set in Render's Environment overrides these. Render injects PORT.
ENV PORT=80 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SESSION_DRIVER=cookie \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=file \
    DB_CONNECTION=mysql \
    LOG_META_PATH=php://stderr

EXPOSE 80
CMD ["start"]
