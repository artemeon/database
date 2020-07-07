#!/bin/sh
wait-for-it.sh db:3306
cd /scripts
php composer install
php ./vendor/bin/phpunit
