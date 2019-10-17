<?php

declare(strict_types=1);

namespace Kajona\System\System\Exceptions;

use Kajona\System\System\Exception;
use Kajona\System\System\Root;
use Throwable;

final class UnableToRetrieveActionsForModelException extends Exception
{
    public function __construct(Root $model, Throwable $previousException = null)
    {
        parent::__construct(
            \sprintf('unable to retrieve actions for model of class "%s"', \get_class($model)),
            self::$level_FATALERROR,
            $previousException
        );
    }
}
