<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Controller\Adminhtml\System;

use AxiTrace\Tracking\Model\HttpClient\IngestionApiClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Admin AJAX endpoint: POST /admin/axitrace/system/testconnection.
 *
 * Validates the workspace_public_key shape, then probes the AxiTrace tracking
 * domain endpoint (already shipped on event-worker). Used by the system config
 * Test Connection button.
 */
class TestConnection extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'AxiTrace_Tracking::config';

    private const KEY_REGEX = '/^pk_(live|test)_[A-Za-z0-9]{20,64}$/';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly IngestionApiClient $client,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        $key = trim((string) $this->getRequest()->getPostValue('workspace_public_key', ''));
        if ($key === '' || preg_match(self::KEY_REGEX, $key) !== 1) {
            return $result->setData([
                'success' => false,
                'message' => __('Invalid workspace public key. Expected format: pk_live_... or pk_test_... (20–64 chars).')->render(),
            ]);
        }

        try {
            $response = $this->client->probeTrackingDomain($key);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => __('Could not reach AxiTrace API: %1', $e->getMessage())->render(),
            ]);
        }

        $domain = isset($response['domain']) ? (string) $response['domain'] : '';
        $isSuccess = !empty($response['success']);

        if (!$isSuccess) {
            return $result->setData([
                'success' => false,
                'message' => isset($response['error'])
                    ? (string) $response['error']
                    : __('Tracking domain lookup did not succeed.')->render(),
            ]);
        }

        return $result->setData([
            'success' => true,
            'message' => __('Connection verified.')->render(),
            'domain'  => $domain,
        ]);
    }
}
