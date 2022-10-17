ARG APP_USER_NAME=app
ARG APP_USER_UID=1000
ARG APP_USER_GID=1000

FROM php:7-cli AS base
ARG APP_USER_NAME
ARG APP_USER_UID
ARG APP_USER_GID

ENV DD_PHP_TRACER_VERSION=0.80.0
ENV COMPOSER_ALLOW_SUPERUSER 1
ENV APP_ENV prod

WORKDIR /code

RUN apt-get update -q \
    && apt-get install -y --no-install-recommends \
        apt-transport-https \
        ca-certificates \
        git \
        gnupg2 \
        libmcrypt-dev \
        libpq-dev \
        libzip-dev \
        openssh-server \
        software-properties-common \
        sudo \
        unzip \
        wget \
        iproute2 \
    && rm -rf /var/lib/apt/lists/*

# install docker
RUN wget https://download.docker.com/linux/debian/gpg \
    && sudo apt-key add gpg \
    && echo "deb [arch=$(dpkg --print-architecture)] https://download.docker.com/linux/debian $(lsb_release -cs) stable" | sudo tee -a /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-cache policy docker-ce \
    && apt-get -y install docker-ce \
    && rm -rf /var/lib/apt/lists/*

# Datadog
RUN curl -LO "https://github.com/DataDog/dd-trace-php/releases/download/${DD_PHP_TRACER_VERSION}/datadog-setup.php" > /tmp/datadog-setup.php \
 && php /tmp/datadog-setup.php --enable-appsec --enable-profiling --php-bin $(which php) \
 && rm /tmp/datadog-setup.php

# create app user
RUN groupadd -g $APP_USER_GID $APP_USER_NAME \
    && useradd -m -u $APP_USER_UID -g $APP_USER_GID $APP_USER_NAME \
    && usermod -a -G docker $APP_USER_NAME \
    && printf "%s ALL=(ALL:ALL) NOPASSWD: ALL" "$APP_USER_NAME" >> /etc/sudoers.d/$APP_USER_NAME

COPY ./docker/php.ini /usr/local/etc/php/php.ini

RUN docker-php-ext-install pcntl zip \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin/ --filename=composer

COPY composer.* symfony.lock ./
RUN composer install $COMPOSER_FLAGS --no-scripts --no-autoloader

COPY . .
RUN composer install $COMPOSER_FLAGS \
    && chown -R "${APP_USER_NAME}:${APP_USER_NAME}" var/

USER $APP_USER_NAME

CMD ["php", "/code/bin/console", "app:run"]


FROM base AS dev
ARG APP_USER_NAME
USER root

ENV APP_ENV dev
ENV PHPUNIT_RESULT_CACHE /tmp/ #does not work, but should https://github.com/sebastianbergmann/phpunit/issues/3714

# install extensions
RUN pecl channel-update pecl.php.net \
    && pecl config-set php_ini /usr/local/etc/php.ini \
    && yes | pecl install xdebug-2.9.8 \
    && echo "zend_extension=$(find /usr/local/lib/php/extensions/ -name xdebug.so)" > /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.remote_enable=on" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.idekey=PHPSTORM" >> /usr/local/etc/php/conf.d/xdebug.ini

USER $APP_USER_NAME

CMD ["/bin/bash"]
