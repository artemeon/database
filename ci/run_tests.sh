#!/bin/sh
wait-for-it.sh mysql:3306
cd /scripts
php composer install
php ./vendor/bin/phpunit
