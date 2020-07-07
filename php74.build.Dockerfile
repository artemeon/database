
FROM php:7.4

RUN apt-get update \
  && apt-get install -y git libldap2-dev libxslt-dev zlib1g-dev libzip-dev libfreetype6-dev libjpeg62-turbo-dev libpng-dev libpq-dev zip unzip \
  && docker-php-ext-install exif gd opcache sockets xsl zip intl mysqli \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN composer --version \
 && php -v \
 && echo "memory_limit = 1024M " >> /usr/local/etc/php/conf.d/x-docker-php.ini; \
    echo "date.timezone = 'Europe/Berlin' " >> /usr/local/etc/php/conf.d/x-docker-php.ini; \
    echo "phar.readonly = 0 " >> /usr/local/etc/php/conf.d/x-docker-php.ini;

COPY ci/wait-for-it.sh /usr/bin/wait-for-it.sh
COPY ci/run_tests.sh /usr/bin/run_tests.sh

RUN mkdir /scripts
VOLUME /scripts

WORKDIR /scripts
