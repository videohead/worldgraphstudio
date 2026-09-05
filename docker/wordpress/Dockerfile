FROM wordpress:php8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        default-mysql-client \
        ffmpeg \
        git \
        unzip \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar -o /tmp/wp-cli.phar \
    && echo "be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832  /tmp/wp-cli.phar" | sha512sum --check --strict \
    && install -m 0755 /tmp/wp-cli.phar /usr/local/bin/wp \
    && rm /tmp/wp-cli.phar

COPY --from=composer:2.7.7 /usr/bin/composer /usr/local/bin/composer
COPY docker/wordpress/worldgraph.ini /usr/local/etc/php/conf.d/worldgraph.ini

WORKDIR /opt/worldgraph
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress

WORKDIR /var/www/html
