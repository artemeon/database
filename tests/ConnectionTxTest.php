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

namespace Artemeon\Database\Tests;

class ConnectionTxTest extends ConnectionTestCase
{
    public function test()
    {
        $objDB = $this->getConnection();

        $arrFields = array();
        $arrFields["temp_id"] = array("char20", false);
        $arrFields["temp_long"] = array("long", true);
        $arrFields["temp_double"] = array("double", true);
        $arrFields["temp_char10"] = array("char10", true);
        $arrFields["temp_char20"] = array("char20", true);
        $arrFields["temp_char100"] = array("char100", true);
        $arrFields["temp_char254"] = array("char254", true);
        $arrFields["temp_char500"] = array("char500", true);
        $arrFields["temp_text"] = array("text", true);

        $this->assertTrue($objDB->createTable("agp_temp_autotest_tx", $arrFields, array("temp_id")), "testTx createTable");


        $intI = 1;
        $strQuery = "INSERT INTO agp_temp_autotest_tx
            (temp_id, temp_long, temp_double, temp_char10, temp_char20, temp_char100, temp_char254, temp_char500, temp_text)
            VALUES
            ('" . $this->generateSystemid() . "', 123456" . $intI . ", 23.45" . $intI . ", '" . $intI . "', 'char20" . $intI . "', 'char100" . $intI . "', 'char254" . $intI . "', 'char500" . $intI . "', 'text" . $intI . "')";

        $this->assertTrue($objDB->_pQuery($strQuery, []), "testTx insert");

        $strQuery = "SELECT * FROM agp_temp_autotest_tx ORDER BY temp_long ASC";
        $arrRow = $objDB->getPArray($strQuery, array());
        $this->assertEquals(count($arrRow), 1, "testDataBase getRow count");
        $this->assertEquals($arrRow[0]["temp_char10"], "1", "testTx getRow content");

        $objDB->flushQueryCache();
        $objDB->transactionBegin();

        $intI = 2;
        $strQuery = "INSERT INTO agp_temp_autotest_tx
            (temp_id, temp_long, temp_double, temp_char10, temp_char20, temp_char100, temp_char254, temp_char500, temp_text)
            VALUES
            ('" . $this->generateSystemid() . "', 123456" . $intI . ", 23.45" . $intI . ", '" . $intI . "', 'char20" . $intI . "', 'char100" . $intI . "', 'char254" . $intI . "', 'char500" . $intI . "', 'text" . $intI . "')";

        $this->assertTrue($objDB->_pQuery($strQuery, []), "testTx insert");

        $objDB->transactionRollback();
        $arrCount = $objDB->getPRow("SELECT COUNT(*) AS cnt FROM agp_temp_autotest_tx", array());
        $this->assertEquals($arrCount["cnt"], 1, "testTx rollback");

        $objDB->flushQueryCache();

        $objDB->transactionBegin();
        $this->assertTrue($objDB->_pQuery($strQuery, []), "testTx insert");
        $objDB->transactionCommit();

        $arrCount = $objDB->getPRow("SELECT COUNT(*) AS cnt FROM agp_temp_autotest_tx", array());
        $this->assertEquals($arrCount["cnt"], 2, "testTx rollback");

        $objDB->flushQueryCache();


        $strQuery = "DROP TABLE agp_temp_autotest_tx";
        $this->assertTrue($objDB->_pQuery($strQuery, []), "testTx dropTable");

    }

}

