#!/bin/sh
wait-for-it.sh ${DB_HOST}:${DB_PORT} -t 240
cd /scripts
composer install
php ./vendor/bin/phpunit
