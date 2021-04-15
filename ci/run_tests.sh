#!/bin/sh
set -e
if [[ $1 != 'skip-wait' ]]
then
  wait-for-it.sh ${DB_HOST}:${DB_PORT} -t 240
fi
cd /scripts
composer install
php ./vendor/bin/psalm
php ./vendor/bin/phpunit
