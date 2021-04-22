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

/**
 * Simple dto to hold all relevant params required to open a db connection
 *
 * @author sidler@mulchprod.de
 * @since 5.1
 */
class ConnectionParameters
{
    public const SQLITE3_BASE_PATH = 'sqlite3_base_path';

    /**
     * @var string
     */
    private $host;

    /**
     * @var string
     */
    private $username;

    /**
     * @var string
     */
    private $password;

    /**
     * @var string
     */
    private $database;

    /**
     * @var int
     */
    private $port;

    /**
     * @var string
     */
    private $driver;

    /**
     * @var array
     */
    private $attributes;

    /**
     * @param string $host
     * @param string $username
     * @param string $password
     * @param string $database
     * @param int|null $port
     * @param string $driver
     */
    public function __construct(string $host, string $username, string $password, string $database, ?int $port, string $driver)
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
        $this->port = $port;
        $this->driver = $driver;
        $this->attributes = [];
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @return string
     */
    public function getDatabase(): string
    {
        return $this->database;
    }

    /**
     * @return int
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * @return string
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * @param string $key
     * @param mixed $value
     */
    public function setAttribute(string $key, $value)
    {
        $this->attributes[$key] = $value;
    }

    /**
     * @param string $key
     * @return mixed|null
     */
    public function getAttribute(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * @param array $data
     * @return ConnectionParameters
     */
    public static function fromArray(array $data): ConnectionParameters
    {
        return new static(
            $data['dbhost'] ?? '',
            $data['dbusername'] ?? '',
            $data['dbpassword'] ?? '',
            $data['dbname'] ?? '',
            isset($data['dbport']) ? (int) $data['dbport'] : 0,
            $data['dbdriver'] ?? ''
        );
    }
}
