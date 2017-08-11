<?php
/*"******************************************************************************************************
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/
declare(strict_types=1);

namespace Kajona\System\System;

/**
 * Dummy action, rendering an ok button but doing nothing
 *
 * @author sidler@mulchprod.de
 * @since 7.0
 *
 */
class MessagingAlertActionVoid implements MessagingAlertActionInterface
{
    /**
     * @inheritDoc
     */
    public function getAsActionArray(): array
    {
        return ["type" => "void"];
    }

}
