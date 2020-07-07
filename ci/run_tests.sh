#!/bin/sh
wait-for-it.sh db:3306 -t 240
cd /scripts
composer install
php ./vendor/bin/phpunit
