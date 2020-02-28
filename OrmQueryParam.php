<?php

/*"******************************************************************************************************
 *   (c) ARTEMEON Management Partner GmbH
 ********************************************************************************************************/

namespace Kajona\System\System;


/**
 * A single dto to hold an orm param.
 *
 */
class OrmQueryParam
{
    /** @var string */
    private $value;

    /** @var bool  */
    private $escape = false;

    /**
     * OrmQueryParam constructor.
     * @param $value
     * @param bool $escape
     */
    public function __construct(?string $value, bool $escape = false)
    {
        $this->value = $value;
        $this->escape = $escape;
    }

    public function __toString()
    {
        return $this->value ?? '';
    }


    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param mixed $value
     */
    public function setValue($value): void
    {
        $this->value = $value;
    }

    /**
     * @return bool
     */
    public function isEscape(): bool
    {
        return $this->escape;
    }

    /**
     * @param bool $escape
     */
    public function setEscape(bool $escape): void
    {
        $this->escape = $escape;
    }

}
