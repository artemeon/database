<?php
/*"******************************************************************************************************
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/
declare(strict_types=1);

namespace Kajona\System\System;

/**
 * Dummy alert which is used to trigger a specific js function on the frontend. It does not render an actual alert it
 * only executes the attached action directly
 *
 * @author christoph.kappestein@artemeon.de
 * @since 7.1
 */
class MessagingExecution extends MessagingAlert
{
}
