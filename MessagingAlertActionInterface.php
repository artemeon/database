<?php
/*"******************************************************************************************************
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/
declare(strict_types=1);

namespace Kajona\System\System;

/**
 * Base interface for alert actions
 *
 * @author sidler@mulchprod.de
 * @since 7.0
 *
 */
interface MessagingAlertActionInterface
{

    /**
     * Converts the current action to a json-string taken by the frontend in order to
     * build the callback. Must be built to match portal actions.
     *
     * @return array
     */
    public function getAsActionArray(): array;
}
