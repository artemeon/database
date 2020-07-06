
FROM php:7.4

RUN apt-get update \
  && apt-get install -y git zip unzip \
  && docker-php-ext-install exif gd opcache sockets xsl zip intl mysqli \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN composer --version \
 && php -v \
 && echo "memory_limit = 1024M " >> /usr/local/etc/php/conf.d/x-docker-php.ini; \
    echo "date.timezone = 'Europe/Berlin' " >> /usr/local/etc/php/conf.d/x-docker-php.ini; \
    echo "phar.readonly = 0 " >> /usr/local/etc/php/conf.d/x-docker-php.ini;

RUN mkdir /scripts
WORKDIR /scripts
