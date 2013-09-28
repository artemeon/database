<?php
/*"******************************************************************************************************
*   (c) 2007-2013 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
*-------------------------------------------------------------------------------------------------------*
*    $Id: $                                            *
********************************************************************************************************/

/**
 * Class holding a simple plugin manager for admin plugins implementing interface_admin_plugin.
 *
 * Usage:
 * $objPluginManager = new class_pluginmanager();
 * $objPluginManager->loadPluginsFiltered("/admin/statsreports/", self::$STR_PLUGIN_EXTENSION_POINT);
 * $arrPlugins = $this->objPluginManager->getMatchingPluginObjects();
 *
 * @package module_system
 * @author tim.kiefer@kojikui.de
 */
class class_pluginmanager {

    private $objDB;
    private $objToolkit;
    private $objLang;

    /**
     * @var interfacePlugin[]
     */
    private $arrPlugins = array();

    /**
     * @var string
     */
    private $strFilterExtensionPoints = "";

    /**
     * Sets the filter extension point
     *
     * @param string $strFilterExtensionPoints
     */
    public function setFilterExtensionPoints($strFilterExtensionPoints) {
        $this->strFilterExtensionPoints = $strFilterExtensionPoints;
    }

    /**
     * Returns the filter extension point
     *
     * @return string
     */
    public function getFilterExtensionPoints() {
        return $this->strFilterExtensionPoints;
    }

    /**
     * Resets the filter by extension point
     */
    public function resetFilterExtensionPoints() {
        $this->strFilterExtensionPoints = "";
    }

    public function __construct() {
        $objCarrier = class_carrier::getInstance();
        $this->objDB = $objCarrier->getObjDB();
        $this->objToolkit = $objCarrier->getObjToolkit("admin");
        $this->objLang = $objCarrier->getObjLang();
    }

    private function addPlugin($objPlugin, $type, $name) {
        $this->arrPlugins[$type][$name] = $objPlugin;
    }

    /**
     * Register a plugin at the plugin manager
     *
     * @param interface_admin_plugin $objPlugin
     */
    public function registerPlugin(interface_admin_plugin $objPlugin) {
        $rf = new ReflectionClass($objPlugin);
        $arrInterface = $rf->getInterfaceNames();
        $arrInterface = array_filter($arrInterface, function ($objFilter) {
                return strcmp($objFilter, "interface_admin_plugin");
            }
        );
        $this->addPlugin($objPlugin, implode($arrInterface, ","), $objPlugin->getPluginCommand());
    }

    /**
     * Load all Plugins from a given folder.
     *
     * @param $strPath
     */
    public function loadPlugins($strPath) {
        $this->loadPluginsFiltered($strPath, null);
    }

    /**
     * Load Plugins from a given folder filtered by an interface
     *
     * @param $strPath
     * @param $interfaceExtensionPoint
     */
    public function loadPluginsFiltered($strPath, $interfaceExtensionPoint) {
        $arrPlugins = class_resourceloader::getInstance()->getFolderContent($strPath, array(".php"));

        // Register new Folder to Classloader
        class_classloader::getInstance()->addClassFolder($strPath);

        if($interfaceExtensionPoint != null) {
            $this->setFilterExtensionPoints($interfaceExtensionPoint);
        }

        foreach($arrPlugins as $strOnePlugin) {
            $strClassName = str_replace(".php", "", $strOnePlugin);
            /** @var $objPlugin interface_admin_plugin */
            $objPlugin = new $strClassName($this->objDB, $this->objToolkit, $this->objLang);

            if($objPlugin instanceof interface_admin_plugin && $this->matchFilter($objPlugin)) {
                $objPlugin->registerPlugin($this);
            }
        }
    }

    /**
     * Returns all loaded Plugins filtered.
     *
     * @return arr interfacePlugin
     */
    public function getMatchingPluginObjects() {
        return $this->getPluginObjects($this->getFilterExtensionPoints());
    }

    /**
     * Returns all loaded Plugins of an ExtensionPoint
     *
     * @param $strExtensionPoint
     * @return interfacePlugin
     */
    public function getPluginObjects($strExtensionPoint) {
        $arrReturn = $this->arrPlugins[$strExtensionPoint];
        uasort($this->arrPlugins, function (interface_admin_plugin $objA, interface_admin_plugin $objB) {
            return strcmp($objA->getTitle(), $objB->getTitle());
        });

        return $arrReturn;
    }

    /**
     * Return a Plugin by its ExtensionPoint and execution command
     *
     * @param $strExtensionPoint
     * @param $strName
     * @return \interface_admin_plugin|null
     */
    public function getPluginObject($strExtensionPoint, $strName) {
        if(isset ($this->getPluginObjects($strExtensionPoint)[$strName]))
            return $this->getPluginObjects($strExtensionPoint)[$strName];
        else
            return null;
    }


    private function matchFilter(interface_admin_plugin $objPlugin) {
        if($this->getFilterExtensionPoints() == "")
            return true;
        else {
            $rf = new ReflectionClass($objPlugin);
            $arrInterface = $rf->getInterfaceNames();
            return in_array($this->getFilterExtensionPoints(), $arrInterface);
        }
    }

}