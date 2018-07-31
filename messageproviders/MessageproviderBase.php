<?php
/*"******************************************************************************************************
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/

namespace Kajona\System\System\Messageproviders;

/**
 * MessageproviderBase
 *
 * @author christoph.kappestein@artemeon.de
 * @package module_messaging
 * @since 7.1
 */
abstract class MessageproviderBase implements MessageproviderExtendedInterface
{
    /**
     * @inheritdoc
     */
    public function getInitialStatus()
    {
        return self::INITIAL_DEFAULT;
    }
}
