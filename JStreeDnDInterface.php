<?php
/*"******************************************************************************************************
*   (c) 2004-2006 by MulchProductions, www.mulchprod.de                                                 *
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/


namespace Kajona\System\System;

/**
 * Interface for for handling dnd specific things on a tree
 *
 * @package module_system
 * @author stefan.meyer1@yahoo.de
 *
 * @module system
 * @moduleId _system_modul_id_
 *
 *
 */
interface JStreeDnDInterface
{

    /**
     * Checks if the current object can dropped below the given parent object ($strNewParentId)
     *
     * @param $strNewParentId - the new (possible) parent node of the current node
     * @return bool
     * @internal param $strOldParentId - the old (current) parent node of the current node
     */
    public function isValidParentNode($strNewParentId);
}
