<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Block;

use AxiTrace\Tracking\Model\Config\ModuleConfig;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Storefront block that powers `pixel.phtml`.
 *
 * Reads ModuleConfig and the current store id so the template can render
 * window.axitraceConfig with the live values. The actual SDK load is a
 * `<script defer src=...>` in pixel.phtml — this block only supplies the data.
 */
class Pixel extends Template
{
    public function __construct(
        Context $context,
        private readonly ModuleConfig $moduleConfig,
        private readonly StoreManagerInterface $storeManager,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->moduleConfig->isEnabled($this->getStoreId());
    }

    public function getWorkspacePublicKey(): string
    {
        return $this->moduleConfig->getWorkspacePublicKey($this->getStoreId());
    }

    public function getTrackingDomain(): string
    {
        $domain = $this->moduleConfig->getTrackingDomain($this->getStoreId());
        return $domain !== '' ? $domain : 'stat.axitrace.com';
    }

    public function getApiBaseUrl(): string
    {
        return $this->moduleConfig->getApiBaseUrl($this->getStoreId());
    }

    /**
     * @return array<string, bool>
     */
    public function getEventToggles(): array
    {
        $storeId = $this->getStoreId();
        return [
            'purchase'         => $this->moduleConfig->isEventEnabled('purchase', $storeId),
            'add_to_cart'      => $this->moduleConfig->isEventEnabled('add_to_cart', $storeId),
            'view_content'     => $this->moduleConfig->isEventEnabled('view_content', $storeId),
            'view_category'    => $this->moduleConfig->isEventEnabled('view_category', $storeId),
            'page_view'        => $this->moduleConfig->isEventEnabled('page_view', $storeId),
            'begin_checkout'   => $this->moduleConfig->isEventEnabled('begin_checkout', $storeId),
            'add_payment_info' => $this->moduleConfig->isEventEnabled('add_payment_info', $storeId),
        ];
    }

    private function getStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            return 0;
        }
    }
}
