<?php
/*"******************************************************************************************************
*   (c) 2010-2016 ARTEMEON                                                                              *
********************************************************************************************************/

namespace Kajona\System\System\Workflows;

use Kajona\System\System\Carrier;
use Kajona\System\System\Date;
use Kajona\System\System\MessagingMessage;
use Kajona\System\System\MessagingMessagehandler;
use Kajona\System\System\MessagingQueue;
use Kajona\System\System\Security\PasswordRotator;
use Kajona\System\System\ServiceProvider;
use Kajona\System\System\UserUser;
use Kajona\Workflows\System\WorkflowsHandlerInterface;
use Kajona\Workflows\System\WorkflowsWorkflow;

/**
 * WorkflowPasswordRotate
 *
 * @package module_system
 */
class WorkflowPasswordRotate implements WorkflowsHandlerInterface
{
    private $intSendHour = 4;
    private $intSendMin = 35;

    /**
     * @var WorkflowsWorkflow
     */
    private $objWorkflow = null;

    /**
     * @see WorkflowsHandlerInterface::getConfigValueNames()
     */
    public function getConfigValueNames()
    {
        return array(
            Carrier::getInstance()->getObjLang()->getLang("workflow_passwordrotate_val1", "system"),
            Carrier::getInstance()->getObjLang()->getLang("workflow_passwordrotate_val2", "system")
        );
    }

    /**
     * @see WorkflowsHandlerInterface::setConfigValues()
     *
     * @param string $strVal1
     * @param string $strVal2
     * @param string $strVal3
     */
    public function setConfigValues($strVal1, $strVal2, $strVal3)
    {
        if($strVal1 != "" && is_numeric($strVal1)) {
            $this->intSendHour = $strVal1;
        }

        if($strVal2 != "" && is_numeric($strVal2)) {
            $this->intSendMin = $strVal2;
        }

    }

    /**
     * @see WorkflowsHandlerInterface::getDefaultValues()
     */
    public function getDefaultValues()
    {
        return array(4, 35);
    }

    public function setObjWorkflow($objWorkflow)
    {
        $this->objWorkflow = $objWorkflow;
    }

    public function getStrName()
    {
        return Carrier::getInstance()->getObjLang()->getLang("workflow_passwordrotate_title", "system");
    }

    public function execute()
    {
        /** @var PasswordRotator $objPasswordRotator */
        $objPasswordRotator = Carrier::getInstance()->getContainer()->offsetGet(ServiceProvider::STR_PASSWORD_ROTATOR);
        $objPasswordRotator->rotate();

        //trigger again
        return false;
    }

    public function onDelete()
    {
    }

    public function schedule()
    {
        $objDate = new Date();
        $objDate->setNextDay();
        $objDate->setIntHour($this->intSendHour);
        $objDate->setIntMin($this->intSendMin);
        $objDate->setIntSec(0);
        $this->objWorkflow->setObjTriggerdate($objDate);
    }

    public function getUserInterface()
    {
    }

    public function processUserInput($arrParams)
    {
        return;
    }

    public function providesUserInterface()
    {
        return false;
    }

    protected function getNowDate()
    {
        return new Date();
    }
}
