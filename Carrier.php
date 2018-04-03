<?php
/*"******************************************************************************************************
*   (c) 2004-2006 by MulchProductions, www.mulchprod.de                                                 *
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
*-------------------------------------------------------------------------------------------------------*
*	$Id$                                            *
********************************************************************************************************/

namespace Kajona\System\System;

use Kajona\System\Admin\ToolkitAdmin;

/**
 * Heart of the system - granting access to all needed objects e.g. the database or the session-object
 *
 * @package module_system
 * @author sidler@mulchprod.de
 */
class Carrier
{

    const INT_CACHE_TYPE_DBQUERIES = 2;
    const INT_CACHE_TYPE_DBSTATEMENTS = 4;
    const INT_CACHE_TYPE_DBTABLES = 256;
    const INT_CACHE_TYPE_ORMCACHE = 8;
    const INT_CACHE_TYPE_OBJECTFACTORY = 16;
    const INT_CACHE_TYPE_MODULES = 32;
    const INT_CACHE_TYPE_CLASSLOADER = 64;
    const INT_CACHE_TYPE_APC = 128;
    const INT_CACHE_TYPE_CHANGELOG = 512;


    /**
     * Internal array of all params passed globally to the script
     *
     * @var array
     */
    private static $arrParams = null;

    /**
     * Current instance
     *
     * @var Carrier
     */
    private static $objCarrier = null;


    private $objContainer;

    /**
     * Constructor for Carrier, doing nothing important,
     * but being private ;), so use getInstance() instead
     */
    private function __construct()
    {
        // create the global DI container
        $this->boot();
    }

    /**
     * Method to get an instance of Carrier though the constructor is private
     *
     * @return Carrier
     */
    public static function getInstance()
    {

        if (self::$objCarrier == null) {
            self::$objCarrier = new Carrier();
        }

        return self::$objCarrier;
    }

    /**
     * Managing access to the database object. Use ONLY this method to
     * get an instance!
     *
     * @return Database
     */
    public function getObjDB()
    {
        return $this->objContainer[ServiceProvider::STR_DB];
    }


    /**
     * Managing access to the rights object. Use ONLY this method to
     * get an instance!
     *
     * @return Rights
     */
    public function getObjRights()
    {
        return $this->objContainer[ServiceProvider::STR_RIGHTS];
    }

    /**
     * Managing access to the config object. Use ONLY this method to
     * get an instance!
     *
     * @return Config
     */
    public function getObjConfig()
    {
        return $this->objContainer[ServiceProvider::STR_CONFIG];
    }

    /**
     * Managing access to the session object. Use ONLY this method to
     * get an instance!
     *
     * @return Session
     */
    public function getObjSession()
    {
        return $this->objContainer[ServiceProvider::STR_SESSION];
    }


    /**
     * Managing access to the template object. Use ONLY this method to
     * get an instance!
     *
     * @return Template
     */
    public function getObjTemplate()
    {
        return $this->objContainer[ServiceProvider::STR_TEMPLATE];
    }

    /**
     * Managing access to the text object. Use ONLY this method to
     * get an instance!
     *
     * @return Lang
     */
    public function getObjLang()
    {
        return $this->objContainer[ServiceProvider::STR_LANG];
    }


    /**
     * Managing access to the toolkit object. Use ONLY this method to
     * get an instance!
     *
     * @internal string $strArea
     *
     * @return ToolkitAdmin
     */
    public function getObjToolkit($strArea)
    {
        return $this->objContainer[ServiceProvider::STR_ADMINTOOLKIT];
    }

    /**
     * Returns all params passed to the system, including $_GET, $_POST; $_FILES
     * This array may be modified, changes made are available during the whole request!
     *
     * @return array
     */
    public static function getAllParams()
    {
        self::initParamsArray();
        return self::$arrParams;
    }

    /**
     * Writes a param to the current set of params sent with the current requests.
     *
     * @param string $strKey
     * @param mixed $strValue
     *
     * @return void
     */
    public function setParam($strKey, $strValue)
    {
        self::initParamsArray();
        self::$arrParams[$strKey] = $strValue;
    }

    /**
     * Returns the value of a param sent with the current request.
     *
     * @param string $strKey
     *
     * @return mixed
     */
    public function getParam($strKey)
    {
        self::initParamsArray();
        return (isset(self::$arrParams[$strKey]) ? self::$arrParams[$strKey] : "");
    }

    /**
     * Returns the value of a param sent with the current request.
     *
     * @param string $strKey
     *
     * @return bool
     */
    public function issetParam($strKey)
    {
        self::initParamsArray();
        return isset(self::$arrParams[$strKey]);
    }

    /**
     * Internal helper, loads and merges all params passed with the current request.
     *
     * @static
     * @return void
     */
    private static function initParamsArray()
    {
        if (self::$arrParams === null) {
            self::$arrParams = array_merge(getArrayGet(), getArrayPost(), getArrayFiles());
        }
    }

    /**
     * A general helper to flush the systems various caches.
     *
     * @param int $intCacheType A bitmask of caches to be flushed, e.g. Carrier::INT_CACHE_TYPE_DBQUERIES | Carrier::INT_CACHE_TYPE_ORMCACHE
     */
    public function flushCache($intCacheType = 0)
    {

        if ($intCacheType & self::INT_CACHE_TYPE_DBQUERIES) {
            $this->getObjDB()->flushQueryCache();
        }

        if ($intCacheType & self::INT_CACHE_TYPE_DBSTATEMENTS) {
            $this->getObjDB()->flushPreparedStatementsCache();
        }

        if ($intCacheType & self::INT_CACHE_TYPE_DBTABLES) {
            $this->getObjDB()->flushTablesCache();
        }

        if ($intCacheType & self::INT_CACHE_TYPE_ORMCACHE) {
            OrmRowcache::flushCache();
        }

        if ($intCacheType & self::INT_CACHE_TYPE_OBJECTFACTORY) {
            Objectfactory::getInstance()->flushCache();
        }

        if ($intCacheType & self::INT_CACHE_TYPE_MODULES) {
            SystemModule::flushCache();
        }

        if ($intCacheType & self::INT_CACHE_TYPE_CLASSLOADER) {
            Classloader::getInstance()->flushCache();
        }

        if ($intCacheType & self::INT_CACHE_TYPE_APC) {
            CacheManager::getInstance()->flushCache(CacheManager::TYPE_APC);
        }

        if ($intCacheType & self::INT_CACHE_TYPE_CHANGELOG) {
            $objChangelog = new SystemChangelog();
            $objChangelog->processCachedInserts();
        }

    }

    /**
     * Please dont use this method directly it is only intended for internal usage where we have (currently)
     * no other option to access a service. Using this method inside a class makes it difficult to test and creates a
     * global state thus we loose all advantages of the DI. In almost every case you should add the dependency to the
     * constructor instead of getting the service through this method. Inside a controller you should use the inject
     * annotation to get the service. If you are required to use it please try to move the usage to the upmost layer
     * i.e.: Workflow, Event, Controller, Report, Installer. Dont use it inside a model instead try to move the logic
     * into a separate service. If you have no other choice please move the dependencies to the constructor so that
     * it is possible to convert this class to a service (see i.e. Kajona\System\System\MessagingMessagehandler)
     *
     * @return \Pimple\Container
     * @internal
     */
    public function getContainer()
    {
        return $this->objContainer;
    }

    /**
     * Creates a new DI container and register the system services
     */
    public function boot()
    {
        $this->objContainer = new \Pimple\Container();
    }
}

//startup the system....
Carrier::getInstance();
