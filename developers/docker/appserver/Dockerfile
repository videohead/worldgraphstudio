FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ffmpeg \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd mysqli pdo_mysql zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar -o /tmp/wp-cli.phar \
    && echo "be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832  /tmp/wp-cli.phar" | sha512sum --check --strict \
    && install -m 0755 /tmp/wp-cli.phar /usr/local/bin/wp

COPY --from=composer:2.7.7 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
