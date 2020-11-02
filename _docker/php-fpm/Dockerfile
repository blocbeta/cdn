FROM php:7.3-fpm

RUN apt-get update \
    && apt-get install -y libzip-dev git libmagickwand-dev --no-install-recommends

RUN apt-get update \
  && apt-get install -y libmemcached-dev zlib1g-dev \
  && pecl install memcached-3.1.3 \
  && docker-php-ext-enable memcached opcache

RUN pecl install imagick \
  && docker-php-ext-enable imagick

RUN docker-php-ext-install exif

RUN docker-php-ext-install zip \
    && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php \
    && php -r "unlink('composer-setup.php');" \
    && mv composer.phar /usr/local/bin/composer