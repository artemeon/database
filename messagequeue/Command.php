<?php
/*"******************************************************************************************************
 *   (c) ARTEMEON Management Partner GmbH
 *       Published under the GNU LGPL v2.1
 ********************************************************************************************************/

namespace Kajona\System\System\Messagequeue;

/**
 * Command
 *
 * @author christoph.kappestein@artemeon.de
 * @since 7.2
 */
abstract class Command
{
    /**
     * Returns the command data to a simple associative array which can be encoded by json_encode
     *
     * @return array
     */
    abstract public function toArray() : array;

    /**
     * Returns the command object based on the array structure which was previously returns by the toArray method
     *
     * @param array $data
     * @return static
     */
    abstract public static function fromArray(array $data): Command;
}
