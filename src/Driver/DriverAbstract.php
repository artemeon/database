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

namespace Artemeon\Database\Driver;

use Artemeon\Database\ConnectionInterface;
use Artemeon\Database\DriverInterface;
use Artemeon\Database\Schema\DataType;
use Artemeon\Database\Schema\TableIndex;

/**
 * Base class for all database-drivers, holds methods to be used by all drivers
 *
 * @package module_system
 * @since 4.5
 * @author sidler@mulchprod.de
 */
abstract class DriverAbstract implements DriverInterface
{

    protected $arrStatementsCache = array();

    /**
     * @var int
     */
    protected $intAffectedRows = 0;


    /**
     * Detects if the current installation runs on win or unix
     * @return bool
     */
    protected function isWinOs()
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * @inheritDoc
     */
    public function handlesDumpCompression()
    {
        return !$this->isWinOs();
    }

    /**
     * @inheritDoc
     */
    public function hasColumn(string $tableName, string $columnName): bool
    {
        $table = $this->getTableInformation($tableName);
        return in_array(strtolower($columnName), $table->getColumnNames());
    }

    /**
     * @inheritDoc
     */
    public function renameTable($strOldName, $strNewName)
    {
        return $this->_pQuery("ALTER TABLE ".($this->encloseTableName($strOldName))." RENAME TO ".($this->encloseTableName($strNewName)), array());
    }

    /**
     * @inheritDoc
     */
    public function changeColumn($strTable, $strOldColumnName, $strNewColumnName, $strNewDatatype)
    {
        return $this->_pQuery("ALTER TABLE ".($this->encloseTableName($strTable))." CHANGE COLUMN ".($this->encloseColumnName($strOldColumnName)." ".$this->encloseColumnName($strNewColumnName)." ".$this->getDatatype($strNewDatatype)), array());
    }

    /**
     * @inheritDoc
     */
    public function addColumn($strTable, $strColumn, $strDatatype, $bitNull = null, $strDefault = null)
    {
        $strQuery = "ALTER TABLE ".($this->encloseTableName($strTable))." ADD ".($this->encloseColumnName($strColumn)." ".$this->getDatatype($strDatatype));

        if ($bitNull !== null) {
            $strQuery .= $bitNull ? " NULL" : " NOT NULL";
        }

        if ($strDefault !== null) {
            $strQuery .= " DEFAULT ".$strDefault;
        }

        return $this->_pQuery($strQuery, array());
    }

    /**
     * @inheritdoc
     */
    public function createIndex($strTable, $strName, $arrColumns, $bitUnique = false)
    {
        return $this->_pQuery("CREATE ".($bitUnique ? "UNIQUE" : "")." INDEX ".$strName." ON ".$strTable." (" . implode(",", $arrColumns) . ")", []);
    }

    /**
     * @inheritDoc
     */
    public function deleteIndex(string $table, string $index): bool
    {
        return $this->_pQuery("DROP INDEX ".$index, []);
    }


    /**
     * @inheritDoc
     */
    public function addIndex(string $table, TableIndex $index): bool
    {
        return $this->createIndex($table, $index->getName(), explode(",", $index->getDescription()));
    }

    /**
     * @inheritDoc
     */
    public function removeColumn($strTable, $strColumn)
    {
        return $this->_pQuery("ALTER TABLE ".($this->encloseTableName($strTable))." DROP COLUMN ".($this->encloseColumnName($strColumn)), array());
    }

    /**
     * @inheritDoc
     */
    public function triggerMultiInsert($strTable, $arrColumns, $arrValueSets, ConnectionInterface $objDb, ?array $arrEscapes): bool
    {
        $safeColumns = array_map(function ($column) { return $this->encloseColumnName($column); }, $arrColumns);
        $paramsPlaceholder = '(' . implode(',', array_fill(0, count($safeColumns), '?')) . ')';
        $placeholderSets = [];
        $params = [];
        $escapeValues = [];
        foreach ($arrValueSets as $singleSet) {
            $placeholderSets[] = $paramsPlaceholder;
            $params[] = array_values($singleSet);
            if ($arrEscapes !== null) {
                $escapeValues[] = $arrEscapes;
            }
        }
        $insertStatement = 'INSERT INTO ' . $this->encloseTableName($strTable) . ' (' . implode(',', $safeColumns) . ') VALUES ' . implode(',', $placeholderSets);

        return $objDb->_pQuery($insertStatement, array_merge(...$params), $escapeValues !== [] ? array_merge(...$escapeValues) : []);
    }

    /**
     * @inheritDoc
     */
    public function insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns)
    {

        $arrPlaceholder = array();
        $arrMappedColumns = array();

        $arrUpdateKeyValue = array();
        $arrUpdateKeyValueKey = array();
        $arrUpdateParams = array();
        $arrUpdateKeyParams = array();

        $arrPrimaryCompares = array();
        $arrPrimaryValues = array();

        foreach ($arrColumns as $intKey => $strOneCol) {
            $arrPlaceholder[] = "?";
            $arrMappedColumns[] = $this->encloseColumnName($strOneCol);

            if (in_array($strOneCol, $arrPrimaryColumns)) {
                $arrPrimaryCompares[] = $strOneCol." = ? ";
                $arrPrimaryValues[] = $arrValues[$intKey];

                $arrUpdateKeyValueKey[] = $strOneCol." = ? ";
                $arrUpdateKeyParams[] = $arrValues[$intKey];
            } else {
                $arrUpdateKeyValue[] = $strOneCol." = ? ";
                $arrUpdateParams[] = $arrValues[$intKey];
            }
        }

        $arrRow = $this->getPArraySection("SELECT COUNT(*) AS cnt FROM ".$this->encloseTableName($strTable)." WHERE ".implode(" AND ", $arrPrimaryCompares), $arrPrimaryValues, 0, 1);

        if ($arrRow === false) {
            return false;
        }

        $arrSingleRow = isset($arrRow[0]) ? $arrRow[0] : null;

        if ($arrSingleRow === null || $arrSingleRow["cnt"] == "0") {
            $strQuery = "INSERT INTO ".$this->encloseTableName($strTable)." (".implode(", ", $arrMappedColumns).") VALUES (".implode(", ", $arrPlaceholder).")";
            return $this->_pQuery($strQuery, $arrValues);
        } else {
            if (count($arrUpdateKeyValue) === 0) {
                return true;
            }
            $strQuery = "UPDATE ".$this->encloseTableName($strTable)." SET ".implode(", ", $arrUpdateKeyValue)." WHERE ".implode(" AND ", $arrUpdateKeyValueKey);
            return $this->_pQuery($strQuery, array_merge($arrUpdateParams, $arrUpdateKeyParams));
        }
    }

    /**
     * @inheritDoc
     */
    public function getPArraySection($strQuery, $arrParams, $intStart, $intEnd)
    {
        return $this->getPArray($this->appendLimitExpression($strQuery, $intStart, $intEnd), $arrParams);
    }

    /**
     * @inheritDoc
     */
    public function encloseColumnName($strColumn)
    {
        return $strColumn;
    }

    /**
     * @inheritDoc
     */
    public function encloseTableName($strTable)
    {
        return $strTable;
    }

    /**
     * @inheritDoc
     */
    public function flushQueryCache()
    {
        $this->arrStatementsCache = array();
    }

    /**
     * @inheritDoc
     */
    public function escape($strValue)
    {
        return $strValue;
    }

    /**
     * @inheritdoc
     */
    public function getIntAffectedRows()
    {
        return $this->intAffectedRows;
    }

    /**
     * @inheritdoc
     */
    public function appendLimitExpression($strQuery, $intStart, $intEnd)
    {
        //calculate the end-value: mysql limit: start, nr of records, so:
        $intEnd = $intEnd - $intStart + 1;
        //add the limits to the query

        return $strQuery." LIMIT ".$intStart.", ".$intEnd;
    }

    /**
     * @inheritdoc
     */
    public function getConcatExpression(array $parts)
    {
        return 'CONCAT(' . implode(', ', $parts) . ')';
    }

    /**
     * @inheritDoc
     */
    public function convertToDatabaseValue($value, string $type)
    {
        if ($type === DataType::STR_TYPE_CHAR10) {
            return mb_substr($value, 0, 10);
        } elseif ($type === DataType::STR_TYPE_CHAR20) {
            return mb_substr($value, 0, 20);
        } elseif ($type === DataType::STR_TYPE_CHAR100) {
            return mb_substr($value, 0, 100);
        } elseif ($type === DataType::STR_TYPE_CHAR254) {
            return mb_substr($value, 0, 254);
        } elseif ($type === DataType::STR_TYPE_CHAR500) {
            return mb_substr($value, 0, 500);
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    public function getLeastExpression(array $parts): string
    {
        return 'LEAST(' . implode(', ', $parts) . ')';
    }

    public function getSubstringExpression(string $value, int $offset, ?int $length): string
    {
        $parameters = [$value, $offset];
        if (isset($length)) {
            $parameters[] = $length;
        }

        return 'SUBSTRING(' . implode(', ', $parameters) . ')';
    }

    protected function runCommand(string $command)
    {
        $exitCode = null;
        system($command, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Command returned a non-successful exit code: ' . $exitCode . ' through the command: ' . $command);
        }
    }
}
