# ============================================================
# KPI-PROJECT backend — combined container (nginx + php-fpm)
# Build context: ./backend
# Multi-stage: composer deps + vite assets -> production runtime
# ============================================================

# ---------- Stage 1: PHP builder (composer deps + vite assets) ----------
FROM php:8.4-fpm-alpine3.21 AS builder

# Build deps + node (22.x — needed by Vite 8/rolldown) for vite asset compilation
RUN apk add --no-cache \
    nodejs npm git unzip \
    zlib-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
    oniguruma-dev icu-dev curl-dev \
    autoconf build-base

# PHP extensions (matches app requirements: pdo_mysql, bcmath, intl, gd, zip, mbstring, pcntl)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql mbstring zip gd intl bcmath opcache exif pcntl

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install composer deps first (layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

# Copy full source, discover packages, build frontend assets
COPY . .
RUN php artisan package:discover --ansi --no-interaction 2>/dev/null || true
RUN npm install --ignore-scripts --no-audit --no-fund && npm run build

# ---------- Stage 2: Production runtime (combined container) ----------
FROM php:8.4-fpm-alpine3.21

# Runtime packages: nginx + supervisor for combined container
RUN apk add --no-cache \
    nginx supervisor \
    zlib-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
    oniguruma-dev icu-dev curl-dev \
    tzdata

# Copy compiled PHP extensions from builder (same base image — no recompile needed)
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Production PHP settings
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN printf "opcache.enable=1\nopcache.memory_consumption=128\n\
opcache.interned_strings_buffer=8\nopcache.max_accelerated_files=10000\n\
opcache.revalidate_freq=0\nopcache.validate_timestamps=0\n\
upload_max_filesize=20M\npost_max_size=20M\n" \
    > "$PHP_INI_DIR/conf.d/opcache.ini"

WORKDIR /var/www/html
COPY --from=builder /var/www/html /var/www/html

# nginx + supervisor configs + entrypoint
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# Permissions + storage link (uploads dir stays writable via named volume)
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache public/build \
    && chmod -R ug+rwx storage bootstrap/cache \
    && php artisan storage:link --ansi 2>/dev/null || true

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
