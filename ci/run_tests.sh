#!/bin/sh
cd /scripts
php composer install
php ./vendor/bin/phpunit
