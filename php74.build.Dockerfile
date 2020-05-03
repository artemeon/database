
FROM php:7.4

# yes. for java. smh
RUN mkdir -p /usr/share/man/man1

# -- general php and build extension requirements --
RUN apt-get update \
  && apt-get install -y git libldap2-dev libxslt-dev zlib1g-dev libzip-dev libfreetype6-dev libjpeg62-turbo-dev libpng-dev libpq-dev nodejs npm zip unzip ant \
  && docker-php-ext-install exif gd opcache sockets xsl zip intl mysqli \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

## build env
RUN composer --version \
 && php -v \
 && java --version \
 && node -v \
 && npm -v \
 && echo "memory_limit = 1024M " >> /usr/local/etc/php/conf.d/x-docker-php.ini; \
    echo "date.timezone = 'Europe/Berlin' " >> /usr/local/etc/php/conf.d/x-docker-php.ini; \
    echo "phar.readonly = 0 " >> /usr/local/etc/php/conf.d/x-docker-php.ini;
