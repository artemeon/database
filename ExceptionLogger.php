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
 * @author christoph.kappestein@artemeon.de
 * @since 7.2
 */
class ExceptionLogger
{
    /**
     * @var Session
     */
    private $session;

    /**
     * @var Objectfactory
     */
    private $objectFactory;

    /**
     * @var Database
     */
    private $connection;

    /**
     * @var History
     */
    private $history;

    public function __construct(Session $session, Objectfactory $objectFactory, Database $connection)
    {
        $this->session = $session;
        $this->objectFactory = $objectFactory;
        $this->connection = $connection;
        $this->history = new History();
    }

    public function log(\Throwable $exception)
    {
        //send an email to the admin?
        $adminMail = null;
        try {
            if ($this->connection->getBitConnected()) {
                $adminMail = SystemSetting::getConfigValue("_system_admin_email_");
            }
        } catch (Exception $objEx) {
        }

        if (!empty($adminMail)) {
            $mail = "";
            $mail.= "The system installed at "._webpath_." registered an error!\n\n";
            $mail.= "The error message was:\n";
            $mail.= "\t" . $exception->getMessage() . "\n\n";
            $mail.= "The level of this error was:\n";
            $mail.= "\t" . $this->getErrorLevel($exception);

            $mail.= "\n\n";
            $mail.= "File and line number the error was thrown:\n";
            $mail.= "\t" . basename($exception->getFile()) . " in line ".$exception->getLine() . "\n\n";
            $mail.= "Callstack / Backtrace:\n\n";
            $mail.= $exception->getTraceAsString();
            $previous = $exception->getPrevious();
            while ($previous !== null) {
                $mail .= "\n\nPrevious Exception:\n\n";
                $mail .= "\t" . basename($previous->getFile()) . " in line " . $previous->getLine() . "\n\n";
                $mail .= $previous->getTraceAsString();
                $previous = $previous->getPrevious();
            }

            $mail.= "\n\n";
            $mail.= "User: " . $this->session->getUserID() . " (" . $this->session->getUsername() . ")\n";
            $mail.= "Source host: " . getServer("REMOTE_ADDR") . " (" . @gethostbyaddr(getServer("REMOTE_ADDR")) . ")\n";
            $mail.= "Query string: " . getServer("REQUEST_URI") . "\n";
            $mail.= "POST data (selective):\n";

            //set which POST parameters should read out
            $postParams = array("module", "action", "page", "systemid");
            foreach ($postParams as $param) {
                if (getPost($param) != "") {
                    $mail.= "\t" . $param . ": " . getPost($param) . "\n";
                }
            }

            $mail.= "\n\n";
            $mail.= "Last actions called:\n";
            $mail.= "Admin:\n";
            $history = $this->history->getArrAdminHistory();
            if (is_array($history)) {
                foreach ($history as $index => $url) {
                    $mail.= " #" . $index . ": " . $url . "\n";
                }
            }
            $mail.= "\n\n";

            $mail.= "Callstack:\n";
            $mail.= $exception->getTraceAsString();
            $mail.= "\n\n";

            $this->sendMail($adminMail, $mail);
            $this->sendMessage($mail);
        }

        $logMessage = basename($exception->getFile()) . ":" . $exception->getLine() . " -- " . $exception->getMessage();
        Logger::getInstance()->error($logMessage);
    }

    private function sendMail($adminMail, $text)
    {
        $mail = new Mail();
        $mail->setSubject("Error on website "._webpath_." occured!");
        $mail->setSender($adminMail);
        $mail->setText($text);
        $mail->addTo($adminMail);
        $mail->sendMail();
    }

    private function sendMessage($body)
    {
        $group = $this->objectFactory->getObject(SystemSetting::getConfigValue("_admins_group_id_"));

        $message = new MessagingMessage();
        $message->setStrBody($body);
        $message->setObjMessageProvider(new MessageproviderExceptions());
        $messageHandler = new MessagingMessagehandler();
        $messageHandler->sendMessageObject($message, $group);
    }

    private function getErrorLevel(\Throwable $exception)
    {
        if ($exception instanceof Exception) {
            if ($exception->getErrorlevel() == Exception::$level_FATALERROR) {
                return "FATAL ERROR";
            }
            if ($exception->getErrorlevel() == Exception::$level_ERROR) {
                return "REGULAR ERROR";
            }
        }

        return get_class($exception);
    }
}
