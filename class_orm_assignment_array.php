<?php
/*"******************************************************************************************************
*   (c) 2007-2015 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/

/**
 * The orm assignment array is used to create a lazy loading way of handling object-assignments.
 * In most cases this is transparent, so there's no real usage of this class directly.
 *
 * @package module_system
 * @author sidler@mulchprod.de
 * @since 4.8
 */
class class_orm_assignment_array extends ArrayObject {

    /**
     * Indicator on whether the assignments have been loaded or not
     * @var bool
     */
    private $bitInitialized = false;

    /**
     * The referenced target object
     * @var class_root|null
     */
    private $objTargetObject = null;
    private $strProperty = "";

    /**
     * Create a new lazy-loaded array for a mapped assignment-property
     * @param class_root $objTargetObject
     * @param int $strProperty
     */
    function __construct(class_root $objTargetObject, $strProperty) {
        $this->objTargetObject = $objTargetObject;
        $this->strProperty = $strProperty;

        parent::__construct(array());
    }

    /**
     * Triggers the internal loading of the mapped assignments.
     * Real work is only done on first access.
     */
    private function lazyLoadArray() {
        if($this->bitInitialized)
            return;

        $this->bitInitialized = true;

        $objInit = new class_orm_objectinit($this->objTargetObject);

        $objReflection = new class_reflection($this->objTargetObject);
        $arrPropertyParams = $objReflection->getAnnotationValueForProperty($this->strProperty, class_orm_base::STR_ANNOTATION_OBJECTLIST, class_reflection_enum::PARAMS());

        $arrTypeFilter = isset($arrPropertyParams["type"]) ? $arrPropertyParams["type"] : null;

        foreach($objInit->getAssignmentsFromDatabase($this->strProperty) as $strOneId) {

            $objObject = class_objectfactory::getInstance()->getObject($strOneId);
            if($objObject !== null && ($arrTypeFilter == null || count(array_filter($arrTypeFilter, function($strSingleClass) use ($objObject) { return $objObject instanceof $strSingleClass; })) > 0)) {
                $this->append($objObject);
            }
        }
    }

    public function offsetExists($index) {
        $this->lazyLoadArray();
        return parent::offsetExists($index);
    }

    public function offsetGet($index) {
        $this->lazyLoadArray();
        return parent::offsetGet($index);
    }

    public function offsetSet($index, $newval) {
        $this->lazyLoadArray();
        parent::offsetSet($index, $newval);
    }

    public function offsetUnset($index) {
        $this->lazyLoadArray();
        parent::offsetUnset($index);
    }

    public function append($value) {
        $this->lazyLoadArray();
        parent::append($value);
    }

    public function getArrayCopy() {
        $this->lazyLoadArray();
        return parent::getArrayCopy();
    }

    public function count() {
        $this->lazyLoadArray();
        return parent::count();
    }

    public function asort() {
        $this->lazyLoadArray();
        parent::asort();
    }

    public function ksort() {
        $this->lazyLoadArray();
        parent::ksort();
    }

    public function uasort($cmp_function) {
        $this->lazyLoadArray();
        parent::uasort($cmp_function);
    }

    public function uksort($cmp_function) {
        $this->lazyLoadArray();
        parent::uksort($cmp_function);
    }

    public function natsort() {
        $this->lazyLoadArray();
        parent::natsort();
    }

    public function natcasesort() {
        $this->lazyLoadArray();
        parent::natcasesort();
    }

    public function getIterator() {
        $this->lazyLoadArray();
        return parent::getIterator();
    }

    /**
     * @return boolean
     */
    public function getBitInitialized() {
        return $this->bitInitialized;
    }


}