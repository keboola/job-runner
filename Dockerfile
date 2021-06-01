FROM php:7-cli

ENV COMPOSER_ALLOW_SUPERUSER 1
ENV APP_ENV prod

WORKDIR /code

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libzip-dev \
   --no-install-recommends && rm -r /var/lib/apt/lists/*

# install docker
RUN apt-get update -q \
    && apt-get install -y --no-install-recommends \
        apt-transport-https \
        ca-certificates \
        gnupg2 \
        libmcrypt-dev \
        libpq-dev \
        openssh-server \
        software-properties-common \
        sudo \
        wget \
        iproute2 \
    && rm -rf /var/lib/apt/lists/*

# install docker
RUN wget https://download.docker.com/linux/debian/gpg \
    && sudo apt-key add gpg \
    && echo "deb [arch=amd64] https://download.docker.com/linux/debian $(lsb_release -cs) stable" | sudo tee -a /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-cache policy docker-ce \
    && apt-get -y install docker-ce \
    && rm -rf /var/lib/apt/lists/*    
    
COPY ./docker/php.ini /usr/local/etc/php/php.ini

RUN docker-php-ext-install zip \
	&& curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin/ --filename=composer

COPY composer.* ./
RUN composer install $COMPOSER_FLAGS --no-scripts --no-autoloader
COPY . .
RUN composer install $COMPOSER_FLAGS

CMD php /code/bin/console app:run
