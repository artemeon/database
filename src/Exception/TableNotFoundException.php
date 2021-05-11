<?php

/*"******************************************************************************************************
 *   (c) ARTEMEON Management Partner GmbH
 ********************************************************************************************************/

declare(strict_types=1);

namespace Artemeon\Database\Exception;

use Throwable;

class TableNotFoundException extends \Exception
{

    public function __construct(string $message, string $table, Throwable $previous = null)
    {
        $this->message = $message.' '.$table;
        parent::__construct($this->message, 0, $previous);

    }

}
