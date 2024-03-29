<?php

/*
 * This file is part of the Artemeon Core - Web Application Framework.
 *
 * (c) Artemeon <www.artemeon.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Artemeon\Database;

use Artemeon\Database\Exception\DriverNotFoundException;

/**
 * Factory to create a fitting driver based on the driver string.
 */
class DriverFactory
{
    /**
     * @throws DriverNotFoundException
     */
    public function factory(string $driver): DriverInterface
    {
        $class = 'Artemeon\\Database\\Driver\\' . ucfirst($driver) . 'Driver';
        if (!class_exists($class)) {
            throw new DriverNotFoundException('Configured driver ' . $class . ' does not exist');
        }

        return new $class();
    }
}
