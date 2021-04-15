
FROM php:7.4

RUN apt-get update \
  && apt-get install -y git libldap2-dev libxslt-dev zlib1g-dev libzip-dev libfreetype6-dev libjpeg62-turbo-dev libpng-dev libpq-dev zip unzip \
  && docker-php-ext-install exif gd opcache sockets xsl zip intl \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# odbc header
ENV ACCEPT_EULA=Y

RUN curl https://packages.microsoft.com/keys/microsoft.asc | apt-key add - \
 && curl https://packages.microsoft.com/config/debian/10/prod.list > /etc/apt/sources.list.d/mssql-release.list
RUN apt-get update \
  && apt-get install -y msodbcsql17 unixodbc-dev

RUN pecl install sqlsrv \
  && docker-php-ext-enable sqlsrv

RUN composer --version \
 && php -v \
 && echo "memory_limit = 1024M " >> /usr/local/etc/php/conf.d/x-docker-php.ini; \
    echo "date.timezone = 'Europe/Berlin' " >> /usr/local/etc/php/conf.d/x-docker-php.ini; \
    echo "phar.readonly = 0 " >> /usr/local/etc/php/conf.d/x-docker-php.ini;

COPY ci/wait-for-it.sh /usr/bin/wait-for-it.sh
COPY ci/run_tests.sh /usr/bin/run_tests.sh
RUN chmod +x /usr/bin/wait-for-it.sh
RUN chmod +x /usr/bin/run_tests.sh

RUN mkdir /scripts
VOLUME /scripts

WORKDIR /scripts
