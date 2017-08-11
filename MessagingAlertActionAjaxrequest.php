<?php
/*"******************************************************************************************************
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/
declare(strict_types=1);

namespace Kajona\System\System;

/**
 * Action for an alert, forces the frontend to fire an ajax request using the ajax.js module
 *
 * @author sidler@mulchprod.de
 * @since 7.0
 *
 */
class MessagingAlertActionAjaxrequest implements MessagingAlertActionInterface
{

    private $strModule = "";
    private $strAction = "";
    private $strSystemid = "";

    /**
     * MessagingAlertActionAjaxrequest constructor.
     * @param string $strModule
     * @param string $strAction
     * @param string $strSystemid
     */
    public function __construct($strModule, $strAction, $strSystemid)
    {
        $this->strModule = $strModule;
        $this->strAction = $strAction;
        $this->strSystemid = $strSystemid;
    }

    /**
     * @inheritDoc
     */
    public function getAsActionArray(): array
    {
        return ["type" => "ajax", "module" => $this->strModule, "action" => $this->strAction, "systemid" => $this->strSystemid];
    }

    /**
     * @return string
     */
    public function getStrModule(): string
    {
        return $this->strModule;
    }

    /**
     * @param string $strModule
     */
    public function setStrModule(string $strModule)
    {
        $this->strModule = $strModule;
    }

    /**
     * @return string
     */
    public function getStrAction(): string
    {
        return $this->strAction;
    }

    /**
     * @param string $strAction
     */
    public function setStrAction(string $strAction)
    {
        $this->strAction = $strAction;
    }

    /**
     * @return string
     */
    public function getStrSystemid(): string
    {
        return $this->strSystemid;
    }

    /**
     * @param string $strSystemid
     */
    public function setStrSystemid(string $strSystemid)
    {
        $this->strSystemid = $strSystemid;
    }
}
