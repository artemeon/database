
FROM php:7.4

RUN apt-get update \
  && apt-get install -y git libldap2-dev libxslt-dev zlib1g-dev libzip-dev libfreetype6-dev libjpeg62-turbo-dev libpng-dev libpq-dev zip unzip libaio1 \
  && docker-php-ext-install exif gd opcache sockets xsl zip intl mysqli pgsql \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Oracle instantclient
RUN wget https://download.oracle.com/otn_software/linux/instantclient/19600/instantclient-basic-linux.x64-19.6.0.0.0dbru.zip -o /tmp/instantclient-basic.zip
RUN wget https://download.oracle.com/otn_software/linux/instantclient/19600/instantclient-sdk-linux.x64-19.6.0.0.0dbru.zip -o /tmp/instantclient-sdk.zip
RUN unzip /tmp/instantclient-basic.zip -d /usr/local/
RUN unzip /tmp/instantclient-sdk.zip -d /usr/local/
RUN mv /usr/local/instantclient_19_6 /usr/local/instantclient
RUN ln -s /usr/local/instantclient/libclntsh.so.12.1 /usr/local/instantclient/libclntsh.so
RUN ln -s /usr/local/instantclient/libocci.so.12.1 /usr/local/instantclient/libocci.so

ENV LD_LIBRARY_PATH=/usr/local/instantclient
RUN echo 'instantclient,/usr/local/instantclient' | pecl install oci8

RUN docker-php-ext-configure pdo_oci --with-pdo-oci=instantclient,/usr/local/instantclient
RUN docker-php-ext-install pdo_oci
RUN docker-php-ext-enable oci8

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
