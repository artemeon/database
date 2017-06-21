<?php

namespace Kajona\System\System;

/**
 * ServiceLifeCycleModelException
 *
 * @package Kajona\System\System
 * @author christoph.kappestein@gmail.com
 * @since 6.2
 */
class ServiceLifeCycleModelException extends Exception
{
    protected $strSystemId;

    public function __construct($strError, $strSystemId, $intErrorlevel = null, Exception $objPrevious = null)
    {
        parent::__construct($strError, $intErrorlevel ?? self::$level_ERROR, $objPrevious);

        $this->strSystemId = $strSystemId;
    }

    /**
     * @return mixed
     */
    public function getStrSystemId()
    {
        return $this->strSystemId;
    }

    /**
     * @param mixed $strSystemId
     */
    public function setStrSystemId($strSystemId)
    {
        $this->strSystemId = $strSystemId;
    }
}
