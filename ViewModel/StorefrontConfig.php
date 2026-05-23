<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\ViewModel;

use AxiTrace\Tracking\Model\Config\ModuleConfig;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * View model exposing window.axitraceConfig payload to pixel.phtml.
 *
 * Separated from Block\Pixel so it can be reused by Hyva component templates
 * (Hyva layout binds view models to Alpine components and doesn't construct
 * Magento Block instances).
 */
class StorefrontConfig implements ArgumentInterface
{
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled($this->getStoreId());
    }

    /**
     * @return array<string, mixed>
     */
    public function getInlineConfig(): array
    {
        $storeId = $this->getStoreId();
        return [
            'publicKey'      => $this->config->getWorkspacePublicKey($storeId),
            'trackingDomain' => $this->resolveTrackingDomain($storeId),
            'apiBaseUrl'     => $this->config->getApiBaseUrl($storeId),
            'pageType'       => $this->resolvePageType(),
            'toggles'        => [
                'purchase'      => $this->config->isEventEnabled('purchase', $storeId),
                'add_to_cart'   => $this->config->isEventEnabled('add_to_cart', $storeId),
                'view_content'  => $this->config->isEventEnabled('view_content', $storeId),
                'view_category' => $this->config->isEventEnabled('view_category', $storeId),
                'page_view'     => $this->config->isEventEnabled('page_view', $storeId),
            ],
        ];
    }

    private function resolveTrackingDomain(int $storeId): string
    {
        $domain = $this->config->getTrackingDomain($storeId);
        return $domain !== '' ? $domain : 'stat.axitrace.com';
    }

    private function resolvePageType(): string
    {
        $fullAction = (string) $this->request->getFullActionName();
        return match (true) {
            $fullAction === 'catalog_product_view'      => 'product',
            $fullAction === 'catalog_category_view'     => 'category',
            $fullAction === 'checkout_cart_index'       => 'cart',
            $fullAction === 'checkout_onepage_success'  => 'confirmation',
            str_starts_with($fullAction, 'checkout_')    => 'checkout',
            $fullAction === 'cms_index_index'            => 'home',
            $fullAction === 'cms_page_view'              => 'cms',
            default                                       => 'other',
        };
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
