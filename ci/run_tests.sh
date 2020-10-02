#!/bin/sh
set -e
wait-for-it.sh ${DB_HOST}:${DB_PORT} -t 240
cd /scripts
composer install
php ./vendor/bin/psalm
php ./vendor/bin/phpunit
