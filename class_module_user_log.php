<?php
/*"******************************************************************************************************
*   (c) 2004-2006 by MulchProductions, www.mulchprod.de                                                 *
*   (c) 2007-2011 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
*-------------------------------------------------------------------------------------------------------*
*	$Id$                                     *
********************************************************************************************************/

/**
 * Model for a user-login-log
 *
 * @package module_user
 * @author sidler@mulchprod.de
 */
class class_module_user_log extends class_model implements interface_model  {

    /**
     * Constructor to create a valid object
     *
     * @param string $strSystemid (use "" on new objects)
     */
    public function __construct($strSystemid = "") {
        $arrModul = array();
        $arrModul["name"] 				= "module_user";
		$arrModul["moduleId"] 			= _user_modul_id_;
		$arrModul["table"]       		= _dbprefix_."user_log";
		$arrModul["modul"]				= "user";

		//base class
		parent::__construct($arrModul, $strSystemid);

		//init current object
		if($strSystemid != "")
		    $this->initObject();
    }

    /**
     * @see class_model::getObjectTables();
     * @return array
     */
    protected function getObjectTables() {
        return array();
    }

    /**
     * @see class_model::getObjectDescription();
     * @return string
     */
    protected function getObjectDescription() {
        return "";
    }

    /**
     * Initalises the current object, if a systemid was given
     *
     */
    public function initObject() {

    }


    /**
     * Generates a login-log-entry
     *
     * @param int $intStatus
     * @param string $strOtherUsername
     * @return bool
     * @static
     */
    public static function generateLog($intStatus = 1, $strOtherUsername = "") {

        $arrParams = array();

		$strQuery = "INSERT INTO "._dbprefix_."user_log
						(user_log_id, user_log_userid, user_log_date, user_log_status, user_log_ip) VALUES
						(?, ?, ?, ?, ?)";

        $arrParams[] = generateSystemid();

        if($strOtherUsername == "") {
			$arrParams[] = (class_carrier::getInstance()->getObjSession()->getUserID() == "" ? "0" : class_carrier::getInstance()->getObjSession()->getUserID());
        }
		else {
		    $arrParams[] = $strOtherUsername;
		}

        $arrParams[] = time();
        $arrParams[] = (int)$intStatus;
        $arrParams[] = getServer("REMOTE_ADDR");

		return class_carrier::getInstance()->getObjDB()->_pQuery($strQuery, $arrParams);
    }

    /**
     * Returns all login-logs as an array
     *
     * @return mixed
     * @static
     */
    public static function getLoginLogs() {
        $strQuery = "SELECT *
				       FROM "._dbprefix_."user_log as log
			      LEFT JOIN "._dbprefix_."user as user
						ON log.user_log_userid = user.user_id
				   ORDER BY log.user_log_date DESC";
		return class_carrier::getInstance()->getObjDB()->getPArray($strQuery, array());
    }

    /**
     * Returns the number of logins written to the log
     *
     * @return int
     */
    public function getLoginLogsCount() {
        $strQuery = "SELECT COUNT(*)
						FROM "._dbprefix_."user_log as log";
		$arrRow = $this->objDB->getPRow($strQuery, array());

		return $arrRow["COUNT(*)"];
    }

    /**
     * Returns a section of the login-logs as an array
     *
     * @param int $intStart
     * @param int $intEnd
     * @return mixed
     * @static
     */
    public function getLoginLogsSection($intStart, $intEnd) {
        $strQuery = "SELECT *
						FROM "._dbprefix_."user_log as log
							LEFT JOIN "._dbprefix_."user as user
								ON log.user_log_userid = user.user_id
						ORDER BY log.user_log_date DESC";

		return $this->objDB->getPArraySection($strQuery, array(), $intStart, $intEnd);
    }
}