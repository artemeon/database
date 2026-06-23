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

namespace Artemeon\Database\Doctrine\Driver;

use Artemeon\Database\Connection as ArtemeonConnection;
use Artemeon\Database\Doctrine\Connection as DriverConnectionAdapter;
use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use SensitiveParameter;

/**
 * DBAL driver façade for an Artemeon Mysqli driver.
 */
final class MysqlDriver extends AbstractMySQLDriver
{
    public function __construct(private readonly ArtemeonConnection $connection)
    {
    }

    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        $version = $this->connection->fetchOne('SELECT VERSION()');

        return new DriverConnectionAdapter($this->connection, is_string($version) ? $version : '8.0.0');
    }
}
