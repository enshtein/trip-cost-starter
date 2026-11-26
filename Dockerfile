FROM php:8.2-cli

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

COPY . .

RUN mkdir -p /app/var/log

COPY docker/php.ini /usr/local/etc/php/conf.d/99-trip-cost.ini

EXPOSE 8000

CMD ["sh", "-c", "php bin/reset-db.php && php -S 0.0.0.0:8000 -t public"]
