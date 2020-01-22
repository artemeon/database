<?php
/*"******************************************************************************************************
*   (c) 2004-2006 by MulchProductions, www.mulchprod.de                                                 *
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
*-------------------------------------------------------------------------------------------------------*
*	$Id$	                                        *
********************************************************************************************************/

namespace Kajona\System\System;

use Kajona\System\System\Messageproviders\MessageproviderExceptions;


/**
 * This is the common exception to inherit or to throw in the code.
 * Please DO NOT throw a "plain" exception, otherwise logging and error-handling
 * will not work properly!
 *
 * @package module_system
 */
class Exception extends \Exception
{

    /**
     * This level is for common errors happening from time to time ;)
     *
     * @var int
     * @static
     */
    public static $level_ERROR = 1;

    /**
     * Level for really heavy errors. Hopefully not happening that often...
     *
     * @var int
     * @static
     */
    public static $level_FATALERROR = 2;

    private $intErrorlevel;
    private $intDebuglevel;

    /**
     * @param string $strError
     * @param int $intErrorlevel
     * @param Exception|null $objPrevious
     */
    public function __construct($strError, $intErrorlevel = 1, \Throwable $objPrevious = null)
    {
        parent::__construct($strError, 0, $objPrevious);
        $this->intErrorlevel = $intErrorlevel;

        //decide, what to print --> get config-value
        // 0: fatal errors will be displayed
        // 1: fatal and regular errors will be displayed
        $this->intDebuglevel = Carrier::getInstance()->getObjConfig()->getDebug("debuglevel");
    }


    /**
     * Used to handle the current exception.
     * Decides, if the execution should be stopped, or continued.
     * Therefore the errorlevel defines the "weight" of the exception
     *
     * @return void
     * @deprecated - please use the exception logger directly and pass the exception to the log method
     */
    public function processException()
    {
        /** @var ExceptionLogger $exceptionLogger */
        $exceptionLogger = Carrier::getInstance()->getContainer()->offsetGet(ExceptionLogger::class);
        $exceptionLogger->log($this);
    }

    /**
     * Renders the passed exception, either using the xml channes or using the web channel
     *
     * @param Exception $objException
     *
     * @return string
     */
    public static function renderException(Exception $objException)
    {
        if (ResponseObject::getInstance()->getObjEntrypoint()->equals(RequestEntrypointEnum::XML())) {
            $strErrormessage = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            $strErrormessage .= "<error>".xmlSafeString($objException->getMessage())."</error>";
        } else {
            $strErrormessage = "<div class=\"alert alert-danger\" role=\"alert\">\n";
            $strErrormessage .= "<p>An error occurred:<br><b>".(htmlspecialchars($objException->getMessage(), ENT_QUOTES, "UTF-8", false))."</b></p>";

            if ($objException->intErrorlevel == Exception::$level_FATALERROR || Session::getInstance()->isSuperAdmin()) {
                $trace = basename($objException->getFile())." in line ".$objException->getLine()."\n";
                $trace .= $objException->getTraceAsString();
                $previous = $objException->getPrevious();
                while ($previous !== null) {
                    $trace .= "\nPrevious Exception:\n";
                    $trace .= basename($previous->getFile())." in line ".$previous->getLine()."\n\n";
                    $trace .= $previous->getTraceAsString();
                    $previous = $previous->getPrevious();
                }
                $strErrormessage .= "<br><p><pre style='font-size:12px;'>Stacktrace:\n".(htmlspecialchars($trace, ENT_QUOTES, "UTF-8", false))."</pre></p>";
            }

            $strErrormessage .= "<br><p>Please contact the system admin</p>";
            $strErrormessage .= "</div>";
        }

        return $strErrormessage;
    }




    /**
     * This method is called, if an exception was thrown in the code but not caught
     * by an try-catch block.
     *
     * @param Exception $objException
     *
     * @return void
     */
    public static function globalExceptionHandler($objException)
    {
        if (!($objException instanceof Exception)) {
            $objException = new Exception((string)$objException);
        }
        $objException->processException();
        ResponseObject::getInstance()->sendHeaders();

        // in this case we simply render the exception
        echo self::renderException($objException);
    }

    /**
     * @return int
     */
    public function getErrorlevel()
    {
        return $this->intErrorlevel;
    }

    /**
     * @param int $intErrorlevel
     *
     * @return void
     */
    public function setErrorlevel($intErrorlevel)
    {
        $this->intErrorlevel = $intErrorlevel;
    }

    /**
     * @param string $intDebuglevel
     *
     * @return void
     */
    public function setIntDebuglevel($intDebuglevel)
    {
        $this->intDebuglevel = $intDebuglevel;
    }

    /**
     * @return string
     */
    public function getIntDebuglevel()
    {
        return $this->intDebuglevel;
    }

}
