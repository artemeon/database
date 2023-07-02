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
 * Simple DTO to hold all relevant params required to open a db connection.
 */
final class ConnectionParameters
{
    public const SQLITE3_BASE_PATH = 'sqlite3_base_path';

    private array $attributes = [];

    public function __construct(
        private readonly string $host,
        private readonly string $username,
        private readonly string $password,
        private readonly string $database,
        private readonly ?int $port,
        private readonly string $driver
    ) {
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getDatabase(): string
    {
        return $this->database;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['dbhost'] ?? '',
            $data['dbusername'] ?? '',
            $data['dbpassword'] ?? '',
            $data['dbname'] ?? '',
            isset($data['dbport']) ? (int) $data['dbport'] : 0,
            $data['dbdriver'] ?? '',
        );
    }
}
