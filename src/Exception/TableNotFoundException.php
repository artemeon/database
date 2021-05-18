<?php

/*"******************************************************************************************************
 *   (c) ARTEMEON Management Partner GmbH
 ********************************************************************************************************/

declare(strict_types=1);

namespace Artemeon\Database\Exception;

use Throwable;

class TableNotFoundException extends \Exception
{

    public function __construct(string $table, Throwable $previous = null)
    {
        $this->message = 'Table not found:  '.$table;
        parent::__construct($this->message, 0, $previous);

    }

}
