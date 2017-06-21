<?php

namespace Kajona\System\System;

/**
 * ServiceLifeCycleImpl
 *
 * @package Kajona\System\System
 * @author christoph.kappestein@gmail.com
 * @since 6.2
 */
class ServiceLifeCycleImpl implements ServiceLifeCycleInterface
{
    /**
     * @var ServiceLifeCycleFactory
     */
    protected $objFactory;

    /**
     * @param ServiceLifeCycleFactory $objFactory
     */
    public function __construct(ServiceLifeCycleFactory $objFactory)
    {
        $this->objFactory = $objFactory;
    }

    /**
     * @inheritdoc
     */
    public function update(Root $objModel, $strPrevId = false)
    {
        $bitReturn = $objModel->updateObjectToDb($strPrevId);

        if (!$bitReturn) {
            //throw new ServiceLifeCycleUpdateException("Error updating object ".strip_tags($objModel->getStrDisplayName()), $objModel->getSystemid());
        }
    }

    /**
     * @inheritdoc
     */
    public function delete(Root $objModel)
    {
        $bitReturn = $objModel->deleteObject();

        if (!$bitReturn) {
            throw new ServiceLifeCycleLogicDeleteException("Error logic deleting object ".strip_tags($objModel->getStrDisplayName()), $objModel->getSystemid());
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteObjectFromDatabase(Root $objModel)
    {
        $bitReturn = $objModel->deleteObjectFromDatabase();

        if (!$bitReturn) {
            throw new ServiceLifeCycleDeleteException("Error deleting object ".strip_tags($objModel->getStrDisplayName()), $objModel->getSystemid());
        }
    }

    /**
     * @inheritdoc
     */
    public function restore(Root $objModel)
    {
        $bitReturn = $objModel->restoreObject();

        if (!$bitReturn) {
            throw new ServiceLifeCycleRestoreException("Error restoring object ".strip_tags($objModel->getStrDisplayName()), $objModel->getSystemid());
        }
    }

    /**
     * @inheritdoc
     */
    public function copy(Root $objModel, $strNewPrevid = false, $bitChangeTitle = true, $bitCopyChilds = true)
    {
        $bitReturn = $objModel->copyObject($strNewPrevid ?: "", $bitChangeTitle, $bitCopyChilds);

        if (!$bitReturn) {
            throw new ServiceLifeCycleCopyException("Error creating a copy of object ".strip_tags($objModel->getStrDisplayName()), $objModel->getSystemid());
        }

        return $objModel;
    }
}
