<?php
/*"******************************************************************************************************
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/
declare(strict_types=1);

namespace Kajona\System\System;


/**
 * Action for an alert, forces the frontend to load a specific url
 *
 * @author sidler@mulchprod.de
 * @since 7.0
 *
 */
class MessagingAlertActionRedirect implements MessagingAlertActionInterface
{

    private $strUrl;

    /**
     * MessagingAlertActionRedirect constructor.
     * @param $strUrl
     */
    public function __construct($strUrl)
    {
        $this->strUrl = $strUrl;
    }

    /**
     * @inheritDoc
     */
    public function getAsActionArray(): array
    {
        return ["type" => "redirect", "target" => $this->strUrl];
    }

    /**
     * @return mixed
     */
    public function getStrUrl()
    {
        return $this->strUrl;
    }

    /**
     * @param mixed $strUrl
     */
    public function setStrUrl($strUrl)
    {
        $this->strUrl = $strUrl;
    }
}
