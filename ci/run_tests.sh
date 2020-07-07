#!/bin/sh
wait-for-it.sh db:${DB_PORT} -t 240
cd /scripts
composer install
php ./vendor/bin/phpunit
