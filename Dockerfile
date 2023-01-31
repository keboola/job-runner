ARG APP_USER_NAME=app
ARG APP_USER_UID=1000
ARG APP_USER_GID=1000

FROM php:8.2-cli AS base
ARG APP_USER_NAME
ARG APP_USER_UID
ARG APP_USER_GID

ENV DD_PHP_TRACER_VERSION=0.83.1
ENV COMPOSER_ALLOW_SUPERUSER 1
ENV APP_ENV prod

RUN apt-get update -q \
    && apt-get install -y --no-install-recommends \
        apt-transport-https \
        ca-certificates \
        git \
        gnupg2 \
        libmcrypt-dev \
        libpq-dev \
        libzip-dev \
        locales \
        openssh-server \
        software-properties-common \
        sudo \
        unzip \
        wget \
        iproute2 \
    && sed -i 's/^# *\(en_US.UTF-8\)/\1/' /etc/locale.gen \
    && locale-gen \
    && rm -rf /var/lib/apt/lists/*

ENV LANGUAGE=en_US.UTF-8
ENV LANG=en_US.UTF-8
ENV LC_ALL=en_US.UTF-8

# install docker
RUN wget https://download.docker.com/linux/debian/gpg \
    && sudo apt-key add gpg \
    && echo "deb [arch=$(dpkg --print-architecture)] https://download.docker.com/linux/debian $(lsb_release -cs) stable" | sudo tee -a /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-cache policy docker-ce \
    && apt-get -y install docker-ce \
    && rm -rf /var/lib/apt/lists/*

# Datadog
RUN curl -Lf "https://github.com/DataDog/dd-trace-php/releases/download/${DD_PHP_TRACER_VERSION}/datadog-setup.php" > /tmp/datadog-setup.php \
 && php /tmp/datadog-setup.php --php-bin=all --enable-profiling \
 && rm /tmp/datadog-setup.php

# create app user
RUN groupadd -g $APP_USER_GID $APP_USER_NAME \
    && useradd -m -u $APP_USER_UID -g $APP_USER_GID $APP_USER_NAME \
    && usermod -a -G docker $APP_USER_NAME \
    && printf "%s ALL=(ALL:ALL) NOPASSWD: ALL" "$APP_USER_NAME" >> /etc/sudoers.d/$APP_USER_NAME

COPY ./docker/php.ini /usr/local/etc/php/php.ini

RUN docker-php-ext-install pcntl zip \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin/ --filename=composer

WORKDIR /code
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
RUN pecl install xdebug \
 && docker-php-ext-enable xdebug

USER $APP_USER_NAME

CMD ["/bin/bash"]
