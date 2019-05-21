FROM php:7-cli

ENV COMPOSER_ALLOW_SUPERUSER 1
ENV APP_ENV debug

WORKDIR /code

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libzip-dev \
   --no-install-recommends && rm -r /var/lib/apt/lists/*

COPY ./docker/php.ini /usr/local/etc/php/php.ini

RUN docker-php-ext-install zip \
	&& curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin/ --filename=composer

COPY composer.* ./
RUN composer install $COMPOSER_FLAGS --no-scripts --no-autoloader
COPY . .
RUN composer install $COMPOSER_FLAGS

CMD composer ci
