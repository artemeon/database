<?php
/*"******************************************************************************************************
*   (c) 2014-2015 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
*-------------------------------------------------------------------------------------------------------*
*	$Id$	                                            *
********************************************************************************************************/

/**
 * A common base class for class_admin_controller and class_portal_controller.
 * Use one of both to create admin-/portal-views.
 * Do NOT extend this class directly.
 *
 * @package module_system
 * @author sidler@mulchprod.de
 * @since 4.4
 */
abstract class class_abstract_controller {

    const STR_MODULE_ANNOTATION = "@module";
    const STR_MODULEID_ANNOTATION = "@moduleId";


    /**
     * Object containing config-data
     *
     * @var class_config
     */
    protected $objConfig = null;

    /**
     * Toolkit-Object
     *
     * @var class_toolkit_admin|class_toolkit_portal
     */
    protected $objToolkit = null;

    /**
     * Object containing the session-management
     *
     * @var class_session
     */
    protected $objSession = null;

    /**
     * Object to handle templates
     *
     * @var class_template
     */
    protected $objTemplate = null;

    /**
     * Object managing the lang-files
     *
     * @var class_lang
     */
    private $objLang = null;

    /**
     * Instance of the current modules' definition
     *
     * @var class_module_system_module
     */
    private $objModule = null;

    /**
     * The current module to load lang-files from
     * String containing the current module to be used to load texts
     * @var string
     */
    private $strLangBase = "";

    /**
     * Current action-name, used for the controller
     * current action to perform (GET/POST)
     * @var string
     */
    private $strAction = "";

    /**
     * The current systemid as passed by the constructor / params
     * @var string
     */
    private $strSystemid = "";

    /**
     * Array containing information about the current module
     * @var array
     * @deprecated direct access is no longer allowed
     */
    protected $arrModule = array();

    /**
     * String containing the output generated by an internal action
     * @var string
     */
    protected $strOutput = "";

    /**
     * @param string $strSystemid
     */
    public function __construct($strSystemid = "") {

        //Generating all the required objects. For this we use our cool cool carrier-object
        //take care of loading just the necessary objects
        $objCarrier = class_carrier::getInstance();

        $this->objConfig = $objCarrier->getObjConfig();
        $this->objSession = $objCarrier->getObjSession();
        $this->objLang = $objCarrier->getObjLang();
        $this->objTemplate = $objCarrier->getObjTemplate();

        //Setting SystemID
        if($strSystemid == "") {
            $this->setSystemid(class_carrier::getInstance()->getParam("systemid"));
        }
        else {
            $this->setSystemid($strSystemid);
        }


        //And keep the action
        $this->setAction($this->getParam("action"));
        //in most cases, the list is the default action if no other action was passed
        if($this->getAction() == "") {
            $this->setAction("list");
        }

        //try to load the current module-name and the moduleId by reflection
        $objReflection = new class_reflection($this);
        if(!isset($this->arrModule["modul"])) {
            $arrAnnotationValues = $objReflection->getAnnotationValuesFromClass(self::STR_MODULE_ANNOTATION);
            if(count($arrAnnotationValues) > 0)
                $this->setArrModuleEntry("modul", trim($arrAnnotationValues[0]));
                $this->setArrModuleEntry("module", trim($arrAnnotationValues[0]));
        }

        if(!isset($this->arrModule["moduleId"])) {
            $arrAnnotationValues = $objReflection->getAnnotationValuesFromClass(self::STR_MODULEID_ANNOTATION);
            if(count($arrAnnotationValues) > 0)
                $this->setArrModuleEntry("moduleId", constant(trim($arrAnnotationValues[0])));
        }

        $this->strLangBase = $this->getArrModule("modul");
    }


    // --- Common Methods -----------------------------------------------------------------------------------


    /**
     * Writes a value to the params-array
     *
     * @param string $strKey Key
     * @param mixed $mixedValue Value
     *
     * @return void
     */
    public function setParam($strKey, $mixedValue) {
        class_carrier::getInstance()->setParam($strKey, $mixedValue);
    }

    /**
     * Returns a value from the params-Array
     *
     * @param string $strKey
     *
     * @return string|string[] else ""
     */
    public function getParam($strKey) {
        return class_carrier::getInstance()->getParam($strKey);
    }

    /**
     * Returns the complete Params-Array
     *
     * @return mixed
     * @final
     */
    public final function getAllParams() {
        return class_carrier::getAllParams();
    }

    /**
     * returns the action used for the current request
     *
     * @return string
     * @final
     */
    public final function getAction() {
        return (string)$this->strAction;
    }

    /**
     * Overwrites the current action
     *
     * @param string $strAction
     * @return void
     */
    public final function setAction($strAction) {
        $this->strAction = htmlspecialchars(trim($strAction), ENT_QUOTES, "UTF-8", false);
    }



    // --- SystemID & System-Table Methods ------------------------------------------------------------------

    /**
     * Sets the current SystemID
     *
     * @param string $strID
     *
     * @return bool
     * @final
     */
    public final function setSystemid($strID) {
        if(validateSystemid($strID)) {
            $this->strSystemid = $strID;
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * Returns the current SystemID
     *
     * @return string
     * @final
     */
    public final function getSystemid() {
        return $this->strSystemid;
    }

    /**
     * Resets the internal system id
     *
     * @final
     */
    public final function unsetSystemid() {
        $this->strSystemid = "";
    }


    /**
     * Returns the current Text-Object Instance
     *
     * @return class_lang
     */
    protected function getObjLang() {
        return $this->objLang;
    }


    /**
     * Returns the current instance of class_module_system_module, based on the current subclass.
     * Lazy-loading, so loaded on first access.
     *
     * @return class_module_system_module|null
     */
    protected function getObjModule() {
        if($this->objModule == null) {
            $this->objModule = class_module_system_module::getModuleByName($this->getArrModule("modul"));
        }

        return $this->objModule;
    }



    /**
     * Generates a sorted array of systemids, reaching from the passed systemid up
     * until the assigned module-id
     *
     * @param string $strSystemid
     * @param string $strStopSystemid
     *
     * @return mixed
     * @deprecated should be handled by the model-classes instead
     */
    public function getPathArray($strSystemid = "", $strStopSystemid = "") {
        if($strSystemid == "") {
            $strSystemid = $this->getSystemid();
        }
        if($strStopSystemid == "") {
            $strStopSystemid = $this->getObjModule()->getSystemid();
        }

        $objSystemCommon = new class_module_system_common();
        return $objSystemCommon->getPathArray($strSystemid, $strStopSystemid);
    }

    /**
     * Returns a value from the $arrModule array.
     * If the requested key not exists, returns ""
     *
     * @param string $strKey
     *
     * @return string
     */
    public function getArrModule($strKey) {
        if(isset($this->arrModule[$strKey])) {
            return $this->arrModule[$strKey];
        }
        else {
            return "";
        }
    }

    /**
     * Writes a key-value-pair to the arrModule
     *
     * @param string $strKey
     * @param mixed $strValue
     * @return void
     */
    public function setArrModuleEntry($strKey, $strValue) {
        $this->arrModule[$strKey] = $strValue;
    }


    // --- TextMethods --------------------------------------------------------------------------------------

    /**
     * Used to load a property.
     * If you want to provide a list of parameters but no module (automatic loading), pass
     * the parameters array as the second argument (an array). In this case the module is resolved
     * internally.
     *
     * @param string $strName
     * @param string|array $strModule Either the module name (if required) or an array of parameters
     * @param array $arrParameters
     *
     * @return string
     */
    public function getLang($strName, $strModule = "", $arrParameters = array()) {
        if(is_array($strModule))
            $arrParameters = $strModule;

        if($strModule == "" || is_array($strModule)) {
            $strModule = $this->strLangBase;
        }

        //Now we have to ask the Text-Object to return the text
        return $this->getObjLang()->getLang($strName, $strModule, $arrParameters);
    }

    /**
     * Sets the textbase, so the module used to load texts
     *
     * @param string $strLangbase
     * @return void
     */
    protected final function setStrLangBase($strLangbase) {
        $this->strLangBase = $strLangbase;
    }



    // --- PageCache Features -------------------------------------------------------------------------------

    /**
     * Deletes the complete Pages-Cache
     *
     * @return bool
     */
    public function flushCompletePagesCache() {
        return class_cache::flushCache("class_element_portal");
    }

    /**
     * Removes one page from the cache
     *
     * @deprecated use flushCompletePagesCache() instead
     * @return bool
     */
    public function flushPageFromPagesCache() {
        //since the navigation may depend on page-internal characteristics, the complete cache is
        //flushed instead of only flushing the current page
        return self::flushCompletePagesCache();
    }



}