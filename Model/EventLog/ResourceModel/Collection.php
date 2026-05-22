<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\EventLog\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection for the EventLog entity.
 */
class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct(): void
    {
        $this->_init(
            \AxiTrace\Tracking\Model\EventLog\EventLog::class,
            \AxiTrace\Tracking\Model\EventLog\ResourceModel\EventLog::class
        );
    }
}
