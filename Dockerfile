# syntax=docker/dockerfile:1

FROM php:8.4-cli AS base
WORKDIR /app
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl zip opcache pcntl
# 例外のトレースに関数の引数(個人情報を含みうる)を含めない(AC-000-04)
RUN echo 'zend.exception_ignore_args=On' > "$PHP_INI_DIR/conf.d/zz-team-ops.ini"
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN useradd --create-home --uid 1000 app

# Development: the source is mounted from ../team-ops-api by team-ops/compose.yaml.
# Runs as root so it can write vendor/ and storage/ into the mounted folder on any host.
FROM base AS dev
EXPOSE 8000
CMD ["sh", "-c", "composer install && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000"]

# Production: FrankenPHP serves HTTP on :8000 inside the Compose network (ADR-0022, T35).
# HTTPS is handled by Caddy in front, and the web image's nginx forwards /api here.
# The queue worker and the scheduler use this same image with another command.
FROM dunglas/frankenphp:1-php8.4 AS prod
WORKDIR /app
RUN install-php-extensions pdo_mysql intl zip opcache pcntl
# 例外のトレースに関数の引数(個人情報を含みうる)を含めない(AC-000-04)
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo 'zend.exception_ignore_args=On' > "$PHP_INI_DIR/conf.d/zz-team-ops.ini" \
    && printf 'opcache.enable_cli=1\nopcache.validate_timestamps=0\nexpose_php=Off\n' >> "$PHP_INI_DIR/conf.d/zz-team-ops.ini"
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
# A non-root user on a non-privileged port needs no capability (FrankenPHP's docs)
RUN useradd --create-home --uid 1000 app \
    && setcap -r /usr/local/bin/frankenphp \
    && chown -R app:app /config/caddy /data/caddy /app
COPY --chown=app:app . .
USER app
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-cache
# Routes and events do not depend on the settings, so they are cached in the image. The settings
# (config:cache) come from the environment at run time and are not. ProductionSettings refuses
# to boot without TRUSTED_PROXIES, so give it a placeholder for these two commands only
RUN APP_KEY=base64:$(head -c 32 /dev/urandom | base64) TRUSTED_PROXIES=127.0.0.1 php artisan route:cache \
    && APP_KEY=base64:$(head -c 32 /dev/urandom | base64) TRUSTED_PROXIES=127.0.0.1 php artisan event:cache
# HTTP only on :8000. No automatic HTTPS inside the network
ENV SERVER_NAME=:8000
EXPOSE 8000
# The image's own command runs FrankenPHP with its Caddyfile (root /app/public)
