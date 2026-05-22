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
 * Admin AJAX endpoint: POST /admin/axitrace/system/autodetectdomain.
 *
 * Same probe as TestConnection but returns the resolved domain so the JS
 * handler can write it into the tracking_domain field. Kept distinct from
 * TestConnection for clarity in the UI: the buttons have different intents.
 */
class AutoDetectDomain extends Action implements HttpPostActionInterface
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
                'message' => __('Save a valid workspace public key first.')->render(),
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
        if ($domain === '' || empty($response['success'])) {
            return $result->setData([
                'success' => false,
                'message' => __('No verified custom tracking domain found for this workspace.')->render(),
            ]);
        }

        return $result->setData([
            'success' => true,
            'domain'  => $domain,
        ]);
    }
}
