#!/bin/sh
wait-for-it.sh db:3306 -t 60
cd /scripts
composer install
php ./vendor/bin/phpunit