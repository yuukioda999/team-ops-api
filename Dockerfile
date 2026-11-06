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

# Production: dependencies baked in, runs as a non-root user.
FROM base AS prod
RUN chown app:app /app
COPY --chown=app:app . .
USER app
RUN composer install --no-dev --optimize-autoloader --no-interaction
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
