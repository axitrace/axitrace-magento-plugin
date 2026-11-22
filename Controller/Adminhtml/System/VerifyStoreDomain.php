<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Controller\Adminhtml\System;

use AxiTrace\Tracking\Model\Domain\DomainStatusMessages;
use AxiTrace\Tracking\Model\Domain\StoreDomainClaimer;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Admin AJAX endpoint: POST /admin/axitrace/system/verifystoredomain.
 *
 * Claims every storefront host again right away (the "Verify store domain"
 * button) and returns the status lines the configuration page shows.
 */
class VerifyStoreDomain extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'AxiTrace_Tracking::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly StoreDomainClaimer $claimer,
        private readonly DomainStatusMessages $messages,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $status = $this->claimer->claim();

        return $this->jsonFactory->create()->setData([
            'success' => true,
            'lines'   => $this->messages->lines($status),
        ]);
    }
}
