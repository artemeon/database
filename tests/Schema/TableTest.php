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

namespace Artemeon\Database\Tests\Schema;

use Artemeon\Database\Schema\Table;
use Artemeon\Database\Schema\TableColumn;
use Artemeon\Database\Schema\TableIndex;
use Artemeon\Database\Schema\TableKey;
use JsonException;
use PHPUnit\Framework\TestCase;

class TableTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testTable(): void
    {
        $table = new Table('foo');
        $table->addColumn(new TableColumn('bar'));
        $table->addIndex(new TableIndex('baz'));
        $table->addPrimaryKey(new TableKey('pk'));

        $expect = <<<JSON
{
  "name": "foo",
  "indexes": [
    {
      "name": "baz",
      "description": ""
    }
  ],
  "keys": [
    {
      "name": "pk"
    }
  ],
  "columns": [
    {
      "name": "bar",
      "internalType": "",
      "databaseType": "",
      "nullable": true
    }
  ]
}
JSON;
        $actual = json_encode($table, JSON_THROW_ON_ERROR);
        $this->assertJsonStringEqualsJsonString($expect, $actual, $actual);
    }
}
