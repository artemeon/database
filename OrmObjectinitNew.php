<?php
/*"******************************************************************************************************
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/

namespace Kajona\System\System;

/**
 * The orm object init class is used to init an object from the database.
 * Pass an object with a given systemid using the constructor and call
 * initObjectFromDb() afterwards.
 * The mapper will take care to fill all properties with the matching values
 * from the database.
 * Therefore it is essential to have getters and setters for each mapped
 * property (java bean syntax).
 *
 * @package module_system
 * @author sidler@mulchprod.de
 * @since 4.6
 */
class OrmObjectinitNew extends OrmBase
{


    /**
     * Initializes the object from the database.
     * Loads all mapped columns to the properties.
     * Requires that the object is identified by its systemid.
     *
     * @return void
     */
    public function initObjectFromDb()
    {
        //try to do a default init
        $objObject = $this->getObjObject();
        $objReflection = new Reflection($objObject);

        if (validateSystemid($objObject->getSystemid()) && $this->hasTargetTable()) {
            if (OrmRowcache::getCachedInitRow($objObject->getSystemid()) !== null) {
                $arrRow = OrmRowcache::getCachedInitRow($objObject->getSystemid());
            } else {
                $strQuery = "SELECT *
                          ".$this->getQueryBase()."
                           AND system.system_id = ? ";

                $arrRow = Carrier::getInstance()->getObjDB()->getPRow($strQuery, array($objObject->getSystemid()));
            }

            if ($objObject instanceof Root) {
                $objObject->setArrInitRow($arrRow);
            }

            //get the mapped properties
            $arrProperties = $objReflection->getPropertiesWithAnnotation(OrmBase::STR_ANNOTATION_TABLECOLUMN);

            foreach ($arrProperties as $strPropertyName => $strColumn) {
                $arrColumn = explode(".", $strColumn);

                if (count($arrColumn) == 2) {
                    $strColumn = $arrColumn[1];
                }

                if (!isset($arrRow[$strColumn])) {
                    continue;
                }

                //skip columns from the system-table, they are set later on
                if (count($arrColumn) == 2 && $arrColumn[0] == "system") {
                    continue;
                }

                $strSetter = $objReflection->getSetter($strPropertyName);
                if ($strSetter !== null) {
                    //some properties may be set converted, e.g. a date object
                    $strVar = $objReflection->getAnnotationValueForProperty($strPropertyName, "@var");
                    if (strpos($strVar, "Date") !== false && $arrRow[$strColumn] > 0) {
                        $arrRow[$strColumn] = new Date($arrRow[$strColumn]);
                    } elseif ($arrRow[$strColumn] != null && (strtolower(substr($strSetter, 0, 6)) == "setint" || strtolower(substr($strSetter, 0, 7)) == "setlong")) {
                        //different casts on 32bit / 64bit
                        if ($arrRow[$strColumn] > PHP_INT_MAX) {
                            $arrRow[$strColumn] = (float) $arrRow[$strColumn];
                        } else {
                            $arrRow[$strColumn] = (int) $arrRow[$strColumn];
                        }
                    } elseif ($arrRow[$strColumn] != null && strtolower(substr($strSetter, 0, 6)) == "setbit") {
                        $arrRow[$strColumn] = (bool) $arrRow[$strColumn];
                    } elseif ($arrRow[$strColumn] != null && strtolower(substr($strSetter, 0, 8)) == "setfloat") {
                        $arrRow[$strColumn] = (float) $arrRow[$strColumn];
                    }

                    $objObject->{$strSetter}($arrRow[$strColumn]);
                }
            }

            $this->initAssignmentProperties();
        }
    }

    /**
     * Injects the lazy loading objects for assignment properties into the current object
     *
     * @return void
     */
    private function initAssignmentProperties()
    {
        $objObject = $this->getObjObject();
        $objReflection = new Reflection($objObject);

        //get the mapped properties
        $arrProperties = $objReflection->getPropertiesWithAnnotation(OrmBase::STR_ANNOTATION_OBJECTLIST, ReflectionEnum::PARAMS);

        foreach ($arrProperties as $strPropertyName => $arrValues) {
            $objPropertyLazyLoader = new OrmAssignmentArray($objObject, $strPropertyName, $this->getIntCombinedLogicalDeletionConfig());

            $strSetter = $objReflection->getSetter($strPropertyName);
            if ($strSetter !== null) {
                $objObject->{$strSetter}($objPropertyLazyLoader);
            }
        }

    }
}
