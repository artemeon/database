<?php
/*"******************************************************************************************************
*   (c) 2007-2015 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
*-------------------------------------------------------------------------------------------------------*
*    $Id$                                            *
********************************************************************************************************/

use Kajona\System\System\BootstrapCache;
use Kajona\System\System\PharModule;

/**
 * Loader to dynamically resolve and load resources (this is mapping a virtual file-name to a real filename,
 * relative to the project-root).
 * Currently, this includes the loading of templates and lang-files.
 * In addition, the resource-loader supports the listing of files in a given folder.
 * Therefore, the merged file-list of each module below /core may be read.
 *
 * The loader is, as usual, implemented as a singleton.
 * All lookups are cached, so subsequent lookups will be done without filesystem-queries.
 *
 * @package module_system
 * @author sidler@mulchprod.de
 */
class class_resourceloader
{

    /**
     * @var class_resourceloader
     */
    private static $objInstance = null;


    /**
     * Factory method returning an instance of class_resourceloader.
     * The resource-loader implements the singleton pattern.
     *
     * @static
     * @return class_resourceloader
     */
    public static function getInstance()
    {
        if (self::$objInstance == null) {
            self::$objInstance = new class_resourceloader();
        }

        return self::$objInstance;
    }

    /**
     * Constructor, initializes the internal fields
     */
    private function __construct()
    {

    }


    /**
     * Deletes all cached resource-information,
     * so the .cache-files.
     *
     * @return void
     * @deprecated
     */
    public function flushCache()
    {
        class_classloader::getInstance()->flushCache();
    }

    /**
     * Looks up the real filename of a template passed.
     * The filename is the relative path, so adding /templates/[packname] is not required and not allowed.
     *
     * @param string $strTemplateName
     * @param bool $bitScanAdminSkin
     *
     * @throws class_exception
     * @return string The path on the filesystem, relative to the root-folder. Null if the file could not be mapped.
     */
    public function getTemplate($strTemplateName, $bitScanAdminSkin = false)
    {
        $strTemplateName = removeDirectoryTraversals($strTemplateName);
        if (BootstrapCache::getInstance()->getCacheRow(BootstrapCache::CACHE_TEMPLATES, $strTemplateName)) {
            return BootstrapCache::getInstance()->getCacheRow(BootstrapCache::CACHE_TEMPLATES, $strTemplateName);
        }

        $strFilename = null;
        //first try: load the file in the current template-pack
        $strDefaultTemplate = class_module_system_setting::getConfigValue("_packagemanager_defaulttemplate_");
        if (is_file(_realpath_._templatepath_."/".$strDefaultTemplate."/tpl".$strTemplateName)) {
            BootstrapCache::getInstance()->addCacheRow(BootstrapCache::CACHE_TEMPLATES, $strTemplateName, _templatepath_."/".$strDefaultTemplate."/tpl".$strTemplateName);
            return _templatepath_."/".$strDefaultTemplate."/tpl".$strTemplateName;
        }

        //second try: load the file from the default-pack
        if (is_file(_realpath_._templatepath_."/default/tpl".$strTemplateName)) {
            BootstrapCache::getInstance()->addCacheRow(BootstrapCache::CACHE_TEMPLATES, $strTemplateName, _templatepath_."/default/tpl".$strTemplateName);
            return _templatepath_."/default/tpl".$strTemplateName;
        }

        //third try: try to load the file from a given module
        foreach (class_classloader::getInstance()->getArrModules() as $strCorePath => $strOneModule) {
            if (is_dir(_realpath_."/".$strCorePath)) {
                if (is_file(_realpath_."/".$strCorePath."/templates/default/tpl".$strTemplateName)) {
                    $strFilename = "/".$strCorePath."/templates/default/tpl".$strTemplateName;
                    break;
                }
            } elseif (PharModule::isPhar(_realpath_."/".$strCorePath)) {
                $strAbsolutePath = PharModule::getPharStreamPath(_realpath_."/".$strCorePath, "/templates/default/tpl".$strTemplateName);
                if (is_file($strAbsolutePath)) {
                    $strFilename = $strAbsolutePath;
                    break;
                }
            }
        }

        if ($bitScanAdminSkin) {
            //scan directly
            if (is_file(_realpath_.$strTemplateName)) {
                $strFilename = $strTemplateName;
            }

            //prepend path
            if (is_file(_realpath_.class_adminskin_helper::getPathForSkin(class_session::getInstance()->getAdminSkin()).$strTemplateName)) {
                $strFilename = class_adminskin_helper::getPathForSkin(class_session::getInstance()->getAdminSkin()).$strTemplateName;
            }
            elseif (is_file(class_adminskin_helper::getPathForSkin(class_session::getInstance()->getAdminSkin()).$strTemplateName)) {
                $strFilename = class_adminskin_helper::getPathForSkin(class_session::getInstance()->getAdminSkin()).$strTemplateName;
            }
        }

        if ($strFilename === null) {
            throw new class_exception("Required file ".$strTemplateName." could not be mapped on the filesystem.", class_exception::$level_ERROR);
        }

        BootstrapCache::getInstance()->addCacheRow(BootstrapCache::CACHE_TEMPLATES, $strTemplateName, $strFilename);

        return $strFilename;
    }


    /**
     * Looks up the real filename of a template passed.
     * The filename is the relative path, so adding /templates/[packname] is not required and not allowed.
     *
     * @param string $strFolder
     *
     * @return array A list of templates, so the merged result of the current template-pack + default-pack + fallback-files
     */
    public function getTemplatesInFolder($strFolder)
    {

        $arrReturn = array();

        //first try: load the file in the current template-pack
        if (is_dir(_realpath_._templatepath_."/".class_module_system_setting::getConfigValue("_packagemanager_defaulttemplate_")."/tpl".$strFolder)) {
            $arrFiles = scandir(_realpath_._templatepath_."/".class_module_system_setting::getConfigValue("_packagemanager_defaulttemplate_")."/tpl".$strFolder);
            foreach ($arrFiles as $strOneFile) {
                if (substr($strOneFile, -4) == ".tpl") {
                    $arrReturn[] = $strOneFile;
                }
            }
        }

        //second try: load the file from the default-pack
        if (is_dir(_realpath_._templatepath_."/default/tpl".$strFolder)) {
            $arrFiles = scandir(_realpath_._templatepath_."/default/tpl".$strFolder);
            foreach ($arrFiles as $strOneFile) {
                if (substr($strOneFile, -4) == ".tpl") {
                    $arrReturn[] = $strOneFile;
                }
            }
        }

        //third try: try to load the file from given modules
        foreach (class_classloader::getInstance()->getArrModules() as $strCorePath => $strOneModule) {
            if (is_dir(_realpath_."/".$strCorePath."/templates/default/tpl".$strFolder)) {
                $arrFiles = scandir(_realpath_."/".$strCorePath."/templates/default/tpl".$strFolder);
                foreach ($arrFiles as $strOneFile) {
                    if (substr($strOneFile, -4) == ".tpl") {
                        $arrReturn[] = $strOneFile;
                    }
                }
            }
        }


        return $arrReturn;
    }

    /**
     * Loads all lang-files in a passed folder (module or element).
     * The loader resolves the files stored in the project-folder, overwriting the files found in the default-installation.
     * The array returned is based on [path_to_file] = [filename] where the key is relative to the project-root.
     * No caching is done for lang-files, since the entries are cached by the lang-class, too.
     *
     * @param string $strFolder
     *
     * @return array
     */
    public function getLanguageFiles($strFolder)
    {

        if (BootstrapCache::getInstance()->getCacheRow(BootstrapCache::CACHE_LANG, $strFolder) !== false) {
            return BootstrapCache::getInstance()->getCacheRow(BootstrapCache::CACHE_LANG, $strFolder);
        }
        $arrReturn = array();

        //loop all given modules
        foreach (class_classloader::getInstance()->getArrModules() as $strCorePath => $strSingleModule) {
            if (is_dir(_realpath_."/".$strCorePath._langpath_."/".$strFolder)) {
                $arrContent = scandir(_realpath_."/".$strCorePath._langpath_."/".$strFolder);
                foreach ($arrContent as $strSingleEntry) {

                    if (substr($strSingleEntry, -4) == ".php") {
                        $arrReturn["/".$strCorePath._langpath_."/".$strFolder."/".$strSingleEntry] = $strSingleEntry;
                    }
                }
            } elseif (PharModule::isPhar(_realpath_."/".$strCorePath)) {

                $objPhar = new PharModule($strCorePath);
                foreach($objPhar->getContentMap() as $strFilename => $strPharPath) {
                    if (strpos($strFilename, _langpath_."/".$strFolder) !== false) {
                        $arrReturn[$strPharPath] = basename($strPharPath);
                    }
                }
            }
        }

        //check if the same is available in the projects-folder
        if (is_dir(_realpath_._projectpath_._langpath_."/".$strFolder)) {
            $arrContent = scandir(_realpath_._projectpath_._langpath_."/".$strFolder);
            foreach ($arrContent as $strSingleEntry) {

                if (substr($strSingleEntry, -4) == ".php") {

                    $strKey = array_search($strSingleEntry, $arrReturn);
                    if ($strKey !== false) {
                        unset($arrReturn[$strKey]);
                    }
                    $arrReturn[_projectpath_._langpath_."/".$strFolder."/".$strSingleEntry] = $strSingleEntry;

                }

            }
        }

        BootstrapCache::getInstance()->addCacheRow(BootstrapCache::CACHE_LANG, $strFolder, $arrReturn);
        return $arrReturn;
    }

    /**
     * Loads all files in a passed folder, as usual relative to the core whereas the single module-folders may be skipped.
     * The array returned is based on [path_to_file] = [filename] where the key is relative to the project-root.
     * If you want to filter the list of files being returned, pass a callback/closure as the 4th argument. The callback is used
     * as defined in array_filter.
     * If you want to apply a custom function on each (filtered) element, use the 5th param to pass a closure. The callback is passed to array_walk,
     * so the same conventions should be applied,
     *
     * @param string $strFolder
     * @param array $arrExtensionFilter
     * @param bool $bitWithSubfolders includes folders into the return set, otherwise only files will be returned
     * @param Closure $objFilterFunction
     * @param Closure $objWalkFunction
     *
     * @return array
     * @see http://php.net/manual/de/function.array-filter.php
     * @see http://php.net/manual/de/function.array-walk.php
     */
    public function getFolderContent($strFolder, $arrExtensionFilter = array(), $bitWithSubfolders = false, Closure $objFilterFunction = null, Closure $objWalkFunction = null)
    {
        $arrReturn = array();
        $strCachename = md5($strFolder.implode(",", $arrExtensionFilter).($bitWithSubfolders ? "sub" : "nosub"));

        if (BootstrapCache::getInstance()->getCacheRow(BootstrapCache::CACHE_FOLDERCONTENT, $strCachename)) {
            return $this->applyCallbacks(BootstrapCache::getInstance()->getCacheRow(BootstrapCache::CACHE_FOLDERCONTENT, $strCachename), $objFilterFunction, $objWalkFunction);
        }

        //loop all given modules
        foreach (class_classloader::getInstance()->getArrModules() as $strCorePath => $strSingleModule) {
            if (is_dir(_realpath_."/".$strCorePath.$strFolder)) {
                $arrContent = scandir(_realpath_."/".$strCorePath.$strFolder);
                foreach ($arrContent as $strSingleEntry) {

                    if (($strSingleEntry != "." && $strSingleEntry != "..") && ($bitWithSubfolders || is_file(_realpath_."/".$strCorePath.$strFolder."/".$strSingleEntry))) {
                        //Wanted Type?
                        if (count($arrExtensionFilter) == 0) {
                            $arrReturn["/".$strCorePath.$strFolder."/".$strSingleEntry] = $strSingleEntry;
                        }
                        else {
                            //check, if suffix is in allowed list
                            $strFileSuffix = uniSubstr($strSingleEntry, uniStrrpos($strSingleEntry, "."));
                            if (in_array($strFileSuffix, $arrExtensionFilter)) {
                                $arrReturn["/".$strCorePath.$strFolder."/".$strSingleEntry] = $strSingleEntry;
                            }
                        }
                    }

                }
            } elseif (is_file(_realpath_."/".$strCorePath)) {
                $objPhar = new PharModule($strCorePath);

                foreach($objPhar->getContentMap() as $strPath => $strAbsolutePath) {
                    if(strpos($strPath, $strFolder."/".basename($strPath)) === 0) {
                        $arrReturn[$strAbsolutePath] = basename($strPath);
                    }
                }
            }
        }

        //check if the same is available in the projects-folder and overwrite the first hits
        if (is_dir(_realpath_._projectpath_."/".$strFolder)) {
            $arrContent = scandir(_realpath_._projectpath_."/".$strFolder);
            foreach ($arrContent as $strSingleEntry) {

                //Wanted Type?
                if (count($arrExtensionFilter) == 0) {

                    $strKey = array_search($strSingleEntry, $arrReturn);
                    if ($strKey !== false) {
                        unset($arrReturn[$strKey]);
                    }
                    $arrReturn[_projectpath_."/".$strFolder."/".$strSingleEntry] = $strSingleEntry;

                }
                else {
                    //check, if suffix is in allowed list
                    $strFileSuffix = uniSubstr($strSingleEntry, uniStrrpos($strSingleEntry, "."));
                    if (in_array($strFileSuffix, $arrExtensionFilter)) {
                        $strKey = array_search($strSingleEntry, $arrReturn);
                        if ($strKey !== false) {
                            unset($arrReturn[$strKey]);
                        }
                        $arrReturn[_projectpath_."/".$strFolder."/".$strSingleEntry] = $strSingleEntry;
                    }

                }

            }
        }


        BootstrapCache::getInstance()->addCacheRow(BootstrapCache::CACHE_FOLDERCONTENT, $strCachename, $arrReturn);
        return $this->applyCallbacks($arrReturn, $objFilterFunction, $objWalkFunction);
    }

    /**
     * Internal helper to apply the passed callback as an array_filter callback to the list of matching files
     *
     * @param string[] $arrEntries
     * @param callable $objFilterCallback
     * @param callable $objWalkCallback
     *
     * @return array
     */
    private function applyCallbacks($arrEntries, Closure $objFilterCallback = null, Closure $objWalkCallback = null)
    {
        if (($objFilterCallback == null || !is_callable($objFilterCallback)) && ($objWalkCallback == null || !is_callable($objWalkCallback))) {
            return $arrEntries;
        }

        $arrTemp = array();
        foreach ($arrEntries as $strKey => $strValue) {
            $arrTemp[$strKey] = $strValue;
        }

        if ($objFilterCallback !== null) {
            $arrTemp = array_filter($arrTemp, $objFilterCallback);
        }

        if ($objWalkCallback !== null) {
            array_walk($arrTemp, $objWalkCallback);
        }

        return $arrTemp;
    }

    /**
     * Converts a relative path to a real path on the filesystem.
     * If the file can't be found, false is returned instead.
     *
     * @param string $strFile the relative path
     * @param bool $bitCheckProject en- or disables the lookup in the /project folder
     *
     * @return string|bool the absolute path
     *
     * @todo may be cached?
     */
    public function getPathForFile($strFile, $bitCheckProject = true)
    {

        if($bitCheckProject) {

            //fallback on the resourceloader
            $arrContent = $this->getFolderContent(dirname($strFile));
            $strSearchedFilename = basename($strFile);
            foreach ($arrContent as $strPath => $strContentFile) {
                if ($strContentFile == $strSearchedFilename) {
                    return $strPath;
                }
            }
        }
        else {

            //loop all given modules
            foreach (class_classloader::getInstance()->getArrModules() as $strPath => $strSingleModule) {
                if (in_array($strSingleModule, class_classloader::getInstance()->getArrPharModules())) {
                    // phar
                    $strPhar = PharModule::getPharStreamPath(_realpath_."/".$strPath, "/".$strFile);
                    if (is_file($strPhar)) {
                        return $strPhar;//str_replace("//", "/", $strPhar);
                    }
                }
                elseif (is_file(_realpath_."/".$strPath."/".$strFile)) {
                    return str_replace("//", "/", "/".$strPath."/".$strFile);
                }
            }

        }
        return false;
    }


    /**
     * Converts a relative path to a real path on the filesystem.
     * If the file can't be found, false is returned instead.
     *
     * @param string $strFolder the relative path
     * @param bool $bitCheckProject en- or disables the lookup in the /project folder
     *
     * @return string|bool the absolute path
     *
     * @todo may be cached?
     */
    public function getPathForFolder($strFolder)
    {

        //check if the same is available in the projects-folder
        if (is_dir(_realpath_._projectpath_."/".$strFolder)) {
            return str_replace("//", "/", _projectpath_."/".$strFolder);
        }

        //loop all given modules
        foreach (class_classloader::getInstance()->getArrModules() as $strPath => $strSingleModule) {
            if (in_array($strSingleModule, class_classloader::getInstance()->getArrPharModules())) {
                $strPhar = PharModule::getPharStreamPath(_realpath_."/".$strPath, "/".$strFolder);
                if (is_dir($strPhar)) {
                    return $strPhar;//str_replace("//", "/", $strPhar);
                }
            }
            elseif (is_dir(_realpath_."/".$strPath)) {

                if (is_dir(_realpath_."/".$strPath."/".$strFolder)) {
                    return str_replace("//", "/", "/".$strPath."/".$strFolder);

                }

            }

        }

        return false;
    }

    /**
     * Returns the folder the passed module is located in.
     * E.g., when passing module_system, the matching "/core" will be returned.
     *
     * @param string $strModule
     * @param bool $bitPrependRealpath
     *
     * @return string
     */
    public function getCorePathForModule($strModule, $bitPrependRealpath = false)
    {
        $arrFlipped = array_flip(class_classloader::getInstance()->getArrModules());

        if (!array_key_exists($strModule, $arrFlipped)) {
            return null;
        }

        $strPath = uniSubstr(uniStrReplace(array($strModule.".phar", $strModule), "", $arrFlipped[$strModule]), 0, -1);

        return ($bitPrependRealpath ? _realpath_ : "")."/".$strPath;
    }

    /**
     * Returns the web-path of a module, useful when loading static content such as images or css from
     * a phar-based module
     * @param $strModule
     *
     * @return string
     */
    public function getWebPathForModule($strModule)
    {
        $arrPhars = class_classloader::getInstance()->getArrPharModules();
        if (in_array($strModule, $arrPhars)) {
            return "/files/extract/".$strModule;
        }

        return $this->getCorePathForModule($strModule)."/".$strModule;

    }

    /**
     * Returns the core-folder the passed file is located in, e.g. core or core2.
     * Pass a full file-path, so the absolute path and filename.
     *
     * @param string $strPath
     * @param bool $bitPrependRealpath
     *
     * @return string
     */
    public function getCorePathForPath($strPath, $bitPrependRealpath = false)
    {
        $strPath = uniStrReplace(_realpath_."/", "", $strPath);
        $strPath = uniSubstr($strPath, 0, uniStrpos($strPath, "/"));

        return ($bitPrependRealpath ? _realpath_ : "")."/".$strPath;
    }


    /**
     * Returns the list of modules and elements under the /core folder
     *
     * @return array [folder/module] => [module]
     * @deprecated use class_classloader::getInstance()->getArrModules() instead
     */
    public function getArrModules()
    {
        return class_classloader::getInstance()->getArrModules();
    }


}
