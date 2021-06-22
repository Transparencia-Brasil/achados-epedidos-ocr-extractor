FROM php:7.3

WORKDIR /app
COPY . /app

RUN apt-get update && apt-get install -y libmcrypt-dev default-mysql-client libzip-dev \
    libmagickwand-dev git zip unzip curl --no-install-recommends \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && docker-php-ext-install pdo_mysql gd \
    && docker-php-ext-install mysqli \
    && docker-php-ext-install bcmath

#clean cache
RUN rm -rf /var/lib/apt/lists/*

#NECESSARY FOR COMPOSER/LARAVEL
RUN docker-php-ext-install zip

COPY memory-limit-php.ini /usr/local/etc/php/conf.d/memory-limit-php.ini

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

#Pode ser que seja necessário já instalar as dependencias do composer.json
RUN composer update
RUN composer install

CMD php indexador.php
