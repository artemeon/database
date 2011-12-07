<?php
/*"******************************************************************************************************
*   (c) 2004-2006 by MulchProductions, www.mulchprod.de                                                 *
*   (c) 2007-2011 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
*-------------------------------------------------------------------------------------------------------*
*	$Id$	                        *
********************************************************************************************************/

/**
 * Class to represent a single adminwidget
 *
 * @package module_dashboard
 * @author sidler@mulchprod.de
 */
class class_module_dashboard_widget extends class_model implements interface_model, interface_recorddeleted_listener {

    private $strColumn = "";
    private $strUser = "";
    private $strWidgetId = "";
    private $strAspect = "";


	/**
     * Constructor to create a valid object
     *
     * @param string $strSystemid (use "" on new objects)
     */
    public function __construct($strSystemid = "") {

        $this->setArrModuleEntry("modul", "dashboard");
        $this->setArrModuleEntry("moduleId", _system_modul_id_);

		parent::__construct($strSystemid);

		//init current object
		if($strSystemid != "")
		    $this->initObject();
    }


    /**
     * @see class_model::getObjectTables();
     * @return array
     */
    public function getObjectTables() {
        return array(_dbprefix_."dashboard" => "dashboard_id");
    }

    /**
     * @see class_model::getObjectDescription();
     * @return string
     */
    public function getObjectDescription() {
        return "dashboard widget ".$this->getSystemid();
    }

    /**
     * Returns the name to be used when rendering the current object, e.g. in admin-lists.
     * @return string
     */
    public function getStrDisplayName() {
        return "dashboard widget ".$this->getSystemid();
    }


    public function initObject() {
        $strQuery = "SELECT * FROM "._dbprefix_."dashboard,
        						   "._dbprefix_."system
        				WHERE system_id = dashboard_id
        				  AND system_id = ?";

        $arrRow = $this->objDB->getPRow($strQuery, array($this->getSystemid()));
        if(count($arrRow) > 0) {
            $this->setStrUser($arrRow["dashboard_user"]);
            $this->setStrColumn($arrRow["dashboard_column"]);
            $this->setStrWidgetId($arrRow["dashboard_widgetid"]);
            $this->setStrAspect($arrRow["dashboard_aspect"]);
        }

    }

    /**
     * Updates the current widget to the db
     * @return bool
     */
    public function updateStateToDb() {

        $strQuery = "UPDATE "._dbprefix_."dashboard
                   SET dashboard_user = ?,
                       dashboard_column = ?,
                       dashboard_widgetid = ?,
                       dashboard_aspect = ?
                 WHERE dashboard_id = ?";
        return $this->objDB->_pQuery($strQuery, array($this->getStrUser(), $this->getStrColumn(), $this->getStrWidgetId(), $this->getStrAspect(), $this->getSystemid()));
    }

    /**
     * Deletes the current object and the assigned widget from the db
     *
     * @return bool
     */
    public function deleteObject() {
        if($this->getWidgetmodelForCurrentEntry()->deleteObject()) {
            class_logger::getInstance()->addLogRow("deleted dashboardentry ".$this->getSystemid(), class_logger::$levelInfo);
    	    $objRoot = new class_module_system_common();
    	    $strQuery = "DELETE FROM "._dbprefix_."dashboard
                                 WHERE dashboard_id = ?";
            if($this->objDB->_pQuery($strQuery, array($this->getSystemid()))) {
                if($objRoot->deleteSystemRecord($this->getSystemid()))
                    return true;
            }
        }

        return false;
    }

    /**
     * Implementing callback to react on user-delete events
     *
     * Called whenever a records was deleted using the common methods.
     * Implement this method to be notified when a record is deleted, e.g. to to additional cleanups afterwards.
     * There's no need to register the listener, this is done automatically.
     *
     * Make sure to return a matching boolean-value, otherwise the transaction may be rolled back.
     *
     * @param $strSystemid
     *
     * @return bool
     */
    public function handleRecordDeletedEvent($strSystemid) {
        $strQuery = "SELECT dashboard_id FROM "._dbprefix_."dashboard WHERE dashboard_user = ?";
        $arrRows = $this->objDB->getPArray($strQuery, array($strSystemid));
        foreach($arrRows as $arrOneRow) {
            $objWidget = new class_module_dashboard_widget($arrOneRow["dashboard_id"]);
            $objWidget->deleteObject();
        }

        return true;
    }

    /**
     * Looks up the widgets placed in a given column and
     * returns a list of instances
     *
     * @param string $strColumn
     * @param string $strAspectFilter
     * @return array of class_module_system_adminwidget
     */
    public function getWidgetsForColumn($strColumn, $strAspectFilter = "") {

        $arrParams = array();
        $arrParams[] = $this->objSession->getUserID();
        $arrParams[] = $strColumn;
        if($strAspectFilter != "") {
            $arrParams[] = "%".$strAspectFilter."%";
            $strAspectFilter = " AND (dashboard_aspect = '' OR dashboard_aspect IS NULL OR dashboard_aspect LIKE ? )";
        }

        $strQuery = "SELECT system_id
        			  FROM "._dbprefix_."dashboard,
        			  	   "._dbprefix_."system
        			 WHERE dashboard_user = ?
        			   AND dashboard_column = ?
        			   AND dashboard_id = system_id
                       ".$strAspectFilter."
        	     ORDER BY system_sort ASC ";

        $arrRows = $this->objDB->getPArray($strQuery, $arrParams);
        $arrReturn = array();
        if(count($arrRows) > 0) {
            foreach ($arrRows as $arrOneRow) {
            	$arrReturn[] = new class_module_dashboard_widget($arrOneRow["system_id"]);
            }

        }
        return $arrReturn;
    }


    /**
     * Returns the corresponding instance of class_module_system_adminwidget.
     * User class_module_system_adminwidget::getConcreteAdminwidget() to obtain
     * an instance of the real widget
     *
     * @return class_module_system_adminwidget
     */
    public function getWidgetmodelForCurrentEntry() {
        return new class_module_system_adminwidget($this->getStrWidgetId());
    }


    /**
     * Creates an initial set of widgets to be displayed to new users.
     * NOTE: Low-level variant!
     *
     * @param string $strUserid
     * @return bool
     */
    public function createInitialWidgetsForUser($strUserid) {
        $bitReturn = true;


        $arrWidgets = array();
        $arrWidgets[] = array("class_adminwidget_systeminfo", "a:3:{s:3:\"php\";s:7:\"checked\";s:6:\"server\";s:7:\"checked\";s:6:\"kajona\";s:7:\"checked\";}", "column1");
        $arrWidgets[] = array("class_adminwidget_note", "a:1:{s:7:\"content\";s:22:\"Welcome to Kajona V3.4\";}", "column2");
        $arrWidgets[] = array("class_adminwidget_systemlog", "a:1:{s:8:\"nrofrows\";s:1:\"5\";}", "column3");
        $arrWidgets[] = array("class_adminwidget_systemcheck", "a:2:{s:3:\"php\";s:7:\"checked\";s:6:\"kajona\";s:7:\"checked\";}", "column3");

        if(class_exists("class_adminwidget_lastmodifiedpages"))
            $arrWidgets[] = array("class_adminwidget_lastmodifiedpages", "a:1:{s:8:\"nrofrows\";s:1:\"4\";}", "column2");


        foreach($arrWidgets as $arrOneWidget) {
            $objSystemWidget = new class_module_system_adminwidget();
            $objSystemWidget->setStrClass($arrOneWidget[0]);
            $objSystemWidget->setStrContent($arrOneWidget[1]);

            if($objSystemWidget->updateObjectToDb()) {
                $strWidgetId = $objSystemWidget->getSystemid();
                $objDashboard = new class_module_dashboard_widget();
                $objDashboard->setStrColumn($arrOneWidget[2]);
                $objDashboard->setStrUser($strUserid);
                $objDashboard->setStrWidgetId($strWidgetId);
                $objDashboard->setStrAspect(class_module_system_aspect::getCurrentAspectId());
                if(!$objDashboard->updateObjectToDb())
                    $bitReturn = false;
            }
        }


        return $bitReturn;
    }


    //--- GETTERS / SETTERS ---------------------------------------------------------------------------------

    public function setStrColumn($strColumn) {
        $this->strColumn = $strColumn;
    }
    public function setStrUser($strUser) {
        $this->strUser = $strUser;
    }
    public function setStrWidgetId($strWidgetId) {
        $this->strWidgetId = $strWidgetId;
    }

    public function getStrColumn() {
        return $this->strColumn;
    }
    public function getStrUser() {
        return $this->strUser;
    }
    public function getStrWidgetId() {
        return $this->strWidgetId;
    }

    public function getStrAspect() {
        return $this->strAspect;
    }

    public function setStrAspect($strAspect) {
        $this->strAspect = $strAspect;
    }



}


