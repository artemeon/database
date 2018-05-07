<?php
/*"******************************************************************************************************
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/

namespace Kajona\System\System;


/**
 * General information regarding the current php environment
 *
 * @package module_system
 * @author andrii.konoval@artemeon.de
 * @since 7.1
 */
class SysteminfoMail implements SysteminfoInterface
{
    /**
     * Returns the title of the info-block
     *
     * @return string
     */
    public function getStrTitle()
    {
        return Carrier::getInstance()->getObjLang()->getLang("smtp_config", "system");
    }

    /**
     * Returns the contents of the info-block
     *
     * @return mixed
     */
    public function getArrContent()
    {

        $objLang = Carrier::getInstance()->getObjLang();
        $cfg = Config::getInstance("module_system", "mail.php");

        $arrReturn = array();

        $smtpIsEnabled = $cfg->getConfig('smtp_enabled');
        $arrReturn[] = array(
            $objLang->getLang("smtp_enabled", "system"),
            Carrier::getInstance()->getObjLang()->getLang(!empty($smtpIsEnabled) ? "commons_yes" : "commons_no", "system")
        );
        $arrReturn[] = array($objLang->getLang("smtp_host", "system"), $cfg->getConfig('smtp_host'));
        $arrReturn[] = array($objLang->getLang("smtp_port", "system"), $cfg->getConfig('smtp_port'));
        $arrReturn[] = array($objLang->getLang("smtp_encryption", "system"), $cfg->getConfig('smtp_encryption'));
        $arrReturn[] = array($objLang->getLang("smtp_debug", "system"), $cfg->getConfig('smtp_debug'));

        $smtpIsAuthEnabled = $cfg->getConfig('smtp_auth_enabled');
        $arrReturn[] = array(
            $objLang->getLang("smtp_auth_enabled", "system"),
            Carrier::getInstance()->getObjLang()->getLang(!empty($smtpIsAuthEnabled) ? "commons_yes" : "commons_no", "system")
        );
        $arrReturn[] = array($objLang->getLang("smtp_auth_username", "system"), $cfg->getConfig('smtp_auth_username'));
        return $arrReturn;

    }

    /**
     * Returns the name of extension/plugin the objects wants to contribute to.
     *
     * @return string
     */
    public static function getExtensionName()
    {
        return SysteminfoInterface::STR_EXTENSION_POINT;
    }

}
