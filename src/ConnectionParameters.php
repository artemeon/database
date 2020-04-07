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
    public const OCI8_MAX_STRING_SIZE_EXTENDED = 'oci8_max_string_size_extended';
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
     * @var array
     */
    private $attributes;

    /**
     * @param string $host
     * @param string $username
     * @param string $password
     * @param string $database
     * @param int|null $port
     */
    public function __construct(string $host, string $username, string $password, string $database, ?int $port = null)
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
        $this->port = $port;
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
}
