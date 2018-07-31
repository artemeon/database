<?php
/*"******************************************************************************************************
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/

namespace Kajona\System\System\Messageproviders;

/**
 * The extended interface adds some special configuration options to messageproviders.
 * This includes whether it is allowed to switch of a messageprovider or some default values, such as enabled by mail by default.
 *
 * @author sidler@mulchprod.de
 * @since 4.3
 * @package module_messaging
 */
interface MessageproviderExtendedInterface extends MessageproviderInterface
{
    const INITIAL_DEFAULT = 0;

    const INITIAL_STATUS_ACTIVE = 1;
    const INITIAL_STATUS_INACTIVE = 2;

    const INITIAL_EMAIL_ACTIVE = 4;
    const INITIAL_EMAIL_INACTIVE = 8;

    /**
     * If set to true, the messageprovider may not be disabled by the user.
     * Messages are always sent to the user.
     *
     * @return bool
     */
    public function isAlwaysActive();

    /**
     * If set to true, all messages sent by this provider will be sent by mail, too.
     * The user is not allowed to disable the by-mail flag.
     * Set this to true with care.
     *
     * @return mixed
     */
    public function isAlwaysByMail();

    /**
     * This method is queried when the config-view is rendered.
     * It controls whether a message-provider is shown in the config-view or not.
     *
     * @return bool
     * @since 4.5
     */
    public function isVisibleInConfigView();

    /**
     * Returns the default value of the initial status which is an OR connected value of the INITIAL_* constants.
     * You need to explicit set the active or inactive status to change the default value otherwise we use the default
     * value from the MessagingConfig object
     *
     * @return int
     */
    public function getInitialStatus();
}