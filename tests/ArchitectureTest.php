<?php

declare(strict_types=1);

use Artemeon\Database\Driver\DriverAbstract;

arch('Exception')
    ->expect('Artemeon\Database\Exception')
    ->toUseStrictTypes()
    ->toBeClasses()
    ->toExtend(Throwable::class);

arch('Driver')
    ->expect('Artemeon\Database\Driver')
    ->toUseStrictTypes()
    ->toBeClasses()
    ->toExtend(DriverAbstract::class);
