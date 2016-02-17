<?php
/*"******************************************************************************************************
*   (c) 2007-2015 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
*-------------------------------------------------------------------------------------------------------*
*   $Id$                                        *
********************************************************************************************************/

namespace Kajona\System\System;

use Kajona\System\Portal\TemplatemapperInterface;


/**
 * The templatemapper takes an object and scans it for various properties marked to be
 * exported into a template.
 * May be used by modules to map objects to templates without any hassle.
 *
 * @package module_system
 * @author sidler@mulchprod.de
 * @since 4.5
 */
class TemplateMapper {

    const STR_ANNOTATION_TEMPLATEEXPORT = "@templateExport";
    const STR_ANNOTATION_TEMPLATEMAPPER = "@templateMapper";

    /** @var Root */
    private $objObject = null;

    private $arrMapping = array();


    /**
     * @param null $objObject
     */
    function __construct($objObject = null) {
        $this->objObject = $objObject;

        if($objObject !== null)
            $this->readPropertiesFromObject();
    }

    /**
     * Reads the properties marked with templateExport from the current object
     *
     * @return void
     */
    private function readPropertiesFromObject() {
        $objReflection = new Reflection($this->objObject);
        $arrProperties = $objReflection->getPropertiesWithAnnotation(self::STR_ANNOTATION_TEMPLATEEXPORT);

        foreach(array_keys($arrProperties) as $strOneProperty) {
            $strGetter = $objReflection->getGetter($strOneProperty);

            //get the templatemapper
            $strMapper = $objReflection->getAnnotationValueForProperty($strOneProperty, self::STR_ANNOTATION_TEMPLATEMAPPER);
            if($strMapper == null)
                $strMapper = "default";

            $this->addPlaceholder($strOneProperty, $this->objObject->{$strGetter}(), $strMapper);
        }
        $this->addPlaceholder("strSystemid", $this->objObject->getSystemid(), "default");
        $this->addPlaceholder("content_id", $this->objObject->getSystemid(), "default");
    }

    /**
     * Writes the current set of values into the passed template.
     *
     * @param string $strTemplate
     * @param string $strSection
     * @param bool $bitRemovePlaceholder
     *
     * @return string
     */
    public function writeToTemplate($strTemplate, $strSection, $bitRemovePlaceholder = true) {
        $objTemplate = Carrier::getInstance()->getObjTemplate();
        $strIdentifier = $objTemplate->readTemplate($strTemplate, $strSection);

        return $objTemplate->fillTemplate($this->arrMapping, $strIdentifier, $bitRemovePlaceholder);
    }

    /**
     * Adds a single entry to the current set of mapped values
     *
     * @param string $strName
     * @param string $strValue
     * @param string $strTemplateMapper
     *
     * @return void
     */
    public function addPlaceholder($strName, $strValue, $strTemplateMapper = "default") {
        try {
            $objMapper = $this->getMapperInstance($strTemplateMapper);
            $strValue = $objMapper->format($strValue);
        }
        catch(Exception $objException) {
            $strValue = $objException->getMessage();
        }
        $this->arrMapping[$strName] = $strValue;
    }


    /**
     * Loads the validator identified by the passed name.
     *
     * @param string $strName
     * @return TemplatemapperInterface
     * @throws Exception
     */
    private function getMapperInstance($strName) {
        $strClassname = "class_".$strName."_templatemapper";
        if(Resourceloader::getInstance()->getPathForFile("/portal/templatemapper/".$strClassname.".php")) {
            return new $strClassname();
        }
        else
            throw new Exception("failed to load validator of type ".$strClassname, Exception::$level_ERROR);
    }


    /**
     * @param array $arrMapping
     * @return void
     */
    public function setArrMapping($arrMapping) {
        $this->arrMapping = $arrMapping;
    }

    /**
     * @return array
     */
    public function getArrMapping() {
        return $this->arrMapping;
    }




}